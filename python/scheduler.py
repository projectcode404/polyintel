"""
scheduler.py

Process entry point. Configures and runs the APScheduler job loop.

Two jobs run on independent schedules:
  collect_markets   — every MARKET_SYNC_INTERVAL_MINUTES (default: 60)
  collect_snapshots — every SNAPSHOT_INTERVAL_MINUTES    (default:  5)

Design decisions:
- BackgroundScheduler runs jobs in a thread pool (max_workers=2).
  Jobs do not share state — MarketsCollector and SnapshotsCollector are
  separate instances with separate HTTP clients.
- misfire_grace_time=60: if a job is still running when its next fire time
  arrives, APScheduler waits up to 60 seconds before declaring a misfire.
  For the 5-minute snapshot job, this means we tolerate runs up to 5m 60s.
- coalesce=True: if a job is misfired more than once (e.g. process was
  paused), only one catch-up run fires on resume — not N catch-up runs.
- Jobs run IMMEDIATELY at startup (next_run_time=now) so we don't wait
  60 minutes for the first market sync after a deploy.
- Signal handlers (SIGTERM / SIGINT) shut down the scheduler gracefully:
  in-flight jobs complete, HTTP clients are closed, then the process exits.
- Startup performs a DB connectivity check. If the DB is unavailable,
  the process exits immediately with a clear error rather than starting
  a scheduler that will fail every 5 minutes.

Environment variable summary (all in config/settings.py):
  SNAPSHOT_INTERVAL_MINUTES    default: 5
  MARKET_SYNC_INTERVAL_MINUTES default: 60
  LOG_LEVEL                    default: INFO
  LOG_FORMAT                   default: console  (json in prod)
  DB_HOST / DB_PORT / etc.
"""

from __future__ import annotations

import signal
import sys
import time

from apscheduler.schedulers.background import BackgroundScheduler
from apscheduler.executors.pool import ThreadPoolExecutor
from apscheduler.events import EVENT_JOB_ERROR, EVENT_JOB_EXECUTED, JobExecutionEvent

from config.settings import settings
from utils.logger import configure_logging, get_logger

# Configure logging FIRST — before any other module uses logging
configure_logging()

log = get_logger(__name__)


# ---------------------------------------------------------------------------
# Job functions — thin wrappers that instantiate collectors and call .run()
# Collectors are instantiated fresh per-run to avoid stale state.
# The HTTP client inside PolymarketService is re-used via connection pooling.
# ---------------------------------------------------------------------------

_markets_collector = None
_snapshots_collector = None
_stats_collector = None
_signals_collector = None


def _init_collectors() -> None:
    """
    Initialise collector singletons at startup.
    Reusing instances preserves TCP connections across runs (httpx Client).
    """
    global _markets_collector, _snapshots_collector, _stats_collector, _signals_collector

    from collectors.markets_collector import MarketsCollector
    from collectors.snapshots_collector import SnapshotsCollector
    from collectors.stats_collector import StatsCollector
    from collectors.signal_collector import SignalCollector

    _markets_collector = MarketsCollector()
    _snapshots_collector = SnapshotsCollector()
    _stats_collector = StatsCollector()
    _signals_collector = SignalCollector()
    log.info("collectors_initialised")


def job_collect_markets() -> None:
    """
    APScheduler job: synchronise markets from Polymarket Gamma API.
    Runs every MARKET_SYNC_INTERVAL_MINUTES.
    """
    log.info("job_collect_markets_start")
    try:
        result = _markets_collector.run()
        log.info(
            "job_collect_markets_done",
            inserted=result.inserted,
            updated=result.updated,
            errors=result.errors,
            duration=round(result.duration_seconds, 2),
        )
    except Exception as exc:
        # APScheduler catches this too, but we log it here for context
        log.exception("job_collect_markets_failed", error=str(exc))
        raise  # Let APScheduler record the job error event


def job_collect_snapshots() -> None:
    """
    APScheduler job: collect probability snapshots for tracked markets.
    Runs every SNAPSHOT_INTERVAL_MINUTES.
    """
    log.info("job_collect_snapshots_start")
    try:
        result = _snapshots_collector.run()
        log.info(
            "job_collect_snapshots_done",
            written=result.snapshots_written,
            failed=result.markets_failed,
            success_rate=round(result.success_rate, 3),
            duration=round(result.duration_seconds, 2),
        )
    except Exception as exc:
        log.exception("job_collect_snapshots_failed", error=str(exc))
        raise


def job_collect_stats() -> None:
    """
    APScheduler job: compute daily stats (7d volume, 24h momentum).
    Runs every hour to keep stats reasonably fresh without heavy load.
    """
    log.info("job_collect_stats_start")
    try:
        _stats_collector.run()
        log.info("job_collect_stats_done")
    except Exception as exc:
        log.exception("job_collect_stats_failed", error=str(exc))
        raise


def job_collect_signals() -> None:
    """
    APScheduler job: evaluate rules and generate signals.
    Runs every SNAPSHOT_INTERVAL_MINUTES.
    """
    log.info("job_collect_signals_start")
    try:
        _signals_collector.run()
        log.info("job_collect_signals_done")
    except Exception as exc:
        log.exception("job_collect_signals_failed", error=str(exc))
        raise


# ---------------------------------------------------------------------------
# APScheduler event listener — structured logging for all job lifecycle events
# ---------------------------------------------------------------------------

def _on_job_event(event: JobExecutionEvent) -> None:
    if event.exception:
        log.error(
            "scheduler_job_failed",
            job_id=event.job_id,
            error=str(event.exception),
        )
    else:
        log.debug("scheduler_job_executed", job_id=event.job_id)


# ---------------------------------------------------------------------------
# DB health check
# ---------------------------------------------------------------------------

def _check_db_connectivity() -> None:
    """
    Verify we can reach PostgreSQL before starting the scheduler.
    Exits the process on failure with a clear error message.
    """
    from utils.db import get_session
    from sqlalchemy import text

    log.info("db_health_check_start")
    try:
        with get_session() as session:
            session.execute(text("SELECT 1"))
        log.info("db_health_check_passed")
    except Exception as exc:
        log.error("db_health_check_failed", error=str(exc))
        log.error(
            "startup_aborted",
            reason="Cannot connect to PostgreSQL. Check DB_HOST, DB_PORT, DB_PASSWORD.",
        )
        sys.exit(1)


# ---------------------------------------------------------------------------
# Graceful shutdown
# ---------------------------------------------------------------------------

_scheduler: BackgroundScheduler | None = None


def _shutdown(signum: int, frame) -> None:
    """
    Handle SIGTERM / SIGINT gracefully.
    Waits for in-flight jobs to complete before exiting.
    """
    sig_name = signal.Signals(signum).name
    log.info("shutdown_signal_received", signal=sig_name)

    if _scheduler and _scheduler.running:
        log.info("scheduler_shutting_down", wait_for_jobs=True)
        _scheduler.shutdown(wait=True)

    if _markets_collector:
        _markets_collector.close()
    if _snapshots_collector:
        _snapshots_collector.close()

    log.info("shutdown_complete")
    sys.exit(0)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> None:
    global _scheduler

    log.info(
        "polymarket_intelligence_starting",
        version=settings.collector_version,
        log_level=settings.log_level,
        snapshot_interval_minutes=settings.snapshot_interval_minutes,
        market_sync_interval_minutes=settings.market_sync_interval_minutes,
    )

    # 1. DB connectivity check — fail fast
    _check_db_connectivity()

    # 2. Initialise collector singletons
    _init_collectors()

    # 3. Register signal handlers for graceful shutdown
    signal.signal(signal.SIGTERM, _shutdown)
    signal.signal(signal.SIGINT, _shutdown)

    # 4. Configure APScheduler
    executors = {
        # Two threads: one for markets job, one for snapshots job.
        # They never run concurrently (different schedules) but the pool
        # allows both to be scheduled without blocking each other.
        "default": ThreadPoolExecutor(max_workers=4),
    }

    job_defaults = {
        "coalesce": True,          # Run once on catch-up, not N times
        "max_instances": 1,        # Never run the same job concurrently
        "misfire_grace_time": 60,  # Tolerate up to 60s of latency
    }

    _scheduler = BackgroundScheduler(
        executors=executors,
        job_defaults=job_defaults,
        timezone="UTC",
    )

    # Listen to job events for structured logging
    _scheduler.add_listener(_on_job_event, EVENT_JOB_EXECUTED | EVENT_JOB_ERROR)

    # 5. Register jobs
    # run_date=None + next_run_time override fires immediately at startup
    from datetime import datetime as _dt, timezone as _tz, timedelta as _td

    now = _dt.now(_tz.utc)
    startup_delay = _td(seconds=90)   # buffer: markets collector ~30-60s

    _scheduler.add_job(
        job_collect_markets,
        trigger="interval",
        minutes=settings.market_sync_interval_minutes,
        id="collect_markets",
        name="Market Sync (Gamma API)",
        next_run_time=now,
    )

    _scheduler.add_job(
        job_collect_stats,
        trigger="interval",
        minutes=60,
        id="collect_stats",
        name="Daily Stats Precomputation",
        next_run_time=now + startup_delay,
    )

    _scheduler.add_job(
        job_collect_snapshots,
        trigger="interval",
        minutes=settings.snapshot_interval_minutes,
        id="collect_snapshots",
        name="Snapshot Collection (CLOB API)",
        next_run_time=now + startup_delay,
    )

    _scheduler.add_job(
        job_collect_signals,
        trigger="interval",
        minutes=settings.snapshot_interval_minutes,
        id="collect_signals",
        name="Signal Generation (Rules)",
        next_run_time=now + startup_delay,
    )


    # 6. Start
    _scheduler.start()

    log.info(
        "scheduler_started",
        jobs=[job.id for job in _scheduler.get_jobs()],
    )

    # 7. Block the main thread — APScheduler runs in background threads
    try:
        while True:
            time.sleep(60)
            # Log a heartbeat every minute so ops can see the process is alive
            log.debug(
                "scheduler_heartbeat",
                running_jobs=[j.id for j in _scheduler.get_jobs()],
            )
    except (KeyboardInterrupt, SystemExit):
        _shutdown(signal.SIGINT, None)


if __name__ == "__main__":
    main()
