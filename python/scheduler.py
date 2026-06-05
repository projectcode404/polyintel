"""
scheduler.py

Process entry point. Configures and runs the APScheduler job loop.

Jobs and schedules:
  collect_markets   — every MARKET_SYNC_INTERVAL_MINUTES (default: 15)
  collect_snapshots — every SNAPSHOT_INTERVAL_MINUTES    (default:  5)
  collect_stats     — every 60 minutes
  collect_signals   — every SNAPSHOT_INTERVAL_MINUTES    (default:  5)
  evaluate_signals  — every SNAPSHOT_INTERVAL_MINUTES    (default:  5) [Sprint 3]

Design decisions:
  - BackgroundScheduler runs jobs in a thread pool (max_workers=4).
  - misfire_grace_time=60: tolerate up to 60s latency before misfire.
  - coalesce=True: one catch-up run on resume, not N.
  - collect_markets fires IMMEDIATELY at startup (next_run_time=now).
  - All other jobs delayed by startup_delay (90s) so collect_markets
    has time to populate the DB before snapshots/signals run.
  - Cold start sync: collect_markets also runs SYNCHRONOUSLY before the
    scheduler starts. The 90s delay is a safety net; the sync is the
    guarantee. If sync takes >90s (slow API), snapshot still won't see
    an empty DB.
  - Signal handlers (SIGTERM/SIGINT) shut down gracefully.
  - DB connectivity check at startup — fail fast if DB unreachable.

Sprint 3 additions:
  - _signal_evaluator singleton (SignalEvaluator)
  - job_evaluate_signals: evaluates pending signals against resolved
    market outcomes. Runs on same interval as snapshots.
    Delayed 90s + 30s extra so snapshot job completes its first run first.

Environment variables (all in config/settings.py):
  SNAPSHOT_INTERVAL_MINUTES    default: 5
  MARKET_SYNC_INTERVAL_MINUTES default: 15
  LOG_LEVEL                    default: INFO
  LOG_FORMAT                   default: json (prod) | console (dev)
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
# Collector singletons
# Reusing instances preserves TCP connections across runs (httpx Client).
# ---------------------------------------------------------------------------

_markets_collector   = None
_snapshots_collector = None
_stats_collector     = None
_signals_collector   = None
_signal_evaluator    = None    # Sprint 3


def _init_collectors() -> None:
    global _markets_collector, _snapshots_collector
    global _stats_collector, _signals_collector, _signal_evaluator

    from collectors.markets_collector   import MarketsCollector
    from collectors.snapshots_collector import SnapshotsCollector
    from collectors.stats_collector     import StatsCollector
    from collectors.signal_collector    import SignalCollector
    from services.signal_evaluator      import SignalEvaluator    # Sprint 3

    _markets_collector   = MarketsCollector()
    _snapshots_collector = SnapshotsCollector()
    _stats_collector     = StatsCollector()
    _signals_collector   = SignalCollector()
    _signal_evaluator    = SignalEvaluator()

    log.info("collectors_initialised")


# ---------------------------------------------------------------------------
# Cold start sync
# ---------------------------------------------------------------------------

def _cold_start_market_sync() -> None:
    """
    Run market sync SYNCHRONOUSLY before scheduler starts.

    WHY:
      The 90s startup_delay is a best-effort buffer. If collect_markets
      takes >90s (slow API, large dataset, cold network), snapshot and
      signal collectors will still find an empty DB on their first run.

      This sync guarantees markets are in the DB BEFORE the scheduler
      starts, regardless of how long the API takes.

    TRADEOFF:
      Process startup is slower by ~30-60s (typical market sync time).
      This is acceptable — correct first run > fast startup.

    ERROR HANDLING:
      Non-fatal. If sync fails, scheduler starts anyway.
      collect_markets will retry on its first scheduled interval.
      Log clearly so ops can see the cold start failed.
    """
    log.info("cold_start_market_sync_begin")
    try:
        result = _markets_collector.run()
        log.info(
            "cold_start_market_sync_done",
            inserted=result.inserted,
            updated=result.updated,
            errors=result.errors,
            duration=round(result.duration_seconds, 2),
        )
        if result.inserted == 0 and result.updated == 0:
            log.warning(
                "cold_start_market_sync_empty",
                hint="API may be unreachable or returned no crypto markets. "
                     "Snapshot collector will retry on first scheduled run.",
            )
    except Exception as exc:
        log.error(
            "cold_start_market_sync_failed",
            error=str(exc),
            hint="Scheduler will start. First snapshot run may find 0 markets.",
        )


# ---------------------------------------------------------------------------
# Job functions — thin wrappers, business logic stays in collectors/services
# ---------------------------------------------------------------------------

def job_collect_markets() -> None:
    """Sync markets from Polymarket Gamma API. Every MARKET_SYNC_INTERVAL_MINUTES."""
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
        log.exception("job_collect_markets_failed", error=str(exc))
        raise


def job_collect_snapshots() -> None:
    """Collect CLOB probability snapshots. Every SNAPSHOT_INTERVAL_MINUTES."""
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
    """Precompute daily stats (7d volume, 24h momentum). Every 60 minutes."""
    log.info("job_collect_stats_start")
    try:
        _stats_collector.run()
        log.info("job_collect_stats_done")
    except Exception as exc:
        log.exception("job_collect_stats_failed", error=str(exc))
        raise


def job_collect_signals() -> None:
    """Evaluate rules and generate signals. Every SNAPSHOT_INTERVAL_MINUTES."""
    log.info("job_collect_signals_start")
    try:
        _signals_collector.run()
        log.info("job_collect_signals_done")
    except Exception as exc:
        log.exception("job_collect_signals_failed", error=str(exc))
        raise


def job_evaluate_signals() -> None:
    """
    Sprint 3 — Evaluate pending signals against resolved market outcomes.

    Runs on same interval as snapshots. Fast no-op when nothing to evaluate.
    Delayed an extra 30s beyond startup_delay so snapshot job completes
    its first run before evaluator checks for newly resolved markets.
    """
    log.info("job_evaluate_signals_start")
    try:
        result = _signal_evaluator.run()
        log.info(
            "job_evaluate_signals_done",
            evaluated=result.evaluated,
            correct=result.correct,
            incorrect=result.incorrect,
            cancelled=result.cancelled,
            win_rate=round(result.win_rate, 4),
            duration=round(result.duration_seconds, 2),
        )
    except Exception as exc:
        log.exception("job_evaluate_signals_failed", error=str(exc))
        raise


# ---------------------------------------------------------------------------
# APScheduler event listener
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

    # 4. Cold start: sync markets synchronously before scheduler starts.
    #    Guarantees DB is populated even if API is slow (>90s).
    #    The startup_delay below is a secondary safety net.
    _cold_start_market_sync()

    # 5. Configure APScheduler
    executors = {
        "default": ThreadPoolExecutor(max_workers=4),
    }
    job_defaults = {
        "coalesce": True,
        "max_instances": 1,
        "misfire_grace_time": 60,
    }

    _scheduler = BackgroundScheduler(
        executors=executors,
        job_defaults=job_defaults,
        timezone="UTC",
    )
    _scheduler.add_listener(_on_job_event, EVENT_JOB_EXECUTED | EVENT_JOB_ERROR)

    # 6. Register jobs
    from datetime import datetime as _dt, timezone as _tz, timedelta as _td
    now = _dt.now(_tz.utc)

    # collect_markets: fires immediately (cold start already ran, but
    # schedule normally so it stays on its interval from startup time)
    _scheduler.add_job(
        job_collect_markets,
        trigger="interval",
        minutes=settings.market_sync_interval_minutes,
        id="collect_markets",
        name="Market Sync (Gamma API)",
        next_run_time=now,
    )

    # All other jobs: delayed 90s — secondary safety net after cold start sync
    startup_delay = _td(seconds=90)

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

    _scheduler.add_job(
        job_collect_stats,
        trigger="interval",
        minutes=60,
        id="collect_stats",
        name="Daily Stats Precomputation",
        next_run_time=now + startup_delay,
    )

    # Sprint 3 — evaluate_signals: extra 30s delay so snapshot job
    # completes its first run before evaluator checks for outcomes
    _scheduler.add_job(
        job_evaluate_signals,
        trigger="interval",
        minutes=settings.snapshot_interval_minutes,
        id="evaluate_signals",
        name="Signal Evaluation (Sprint 3)",
        next_run_time=now + startup_delay + _td(seconds=30),
    )

    # 7. Start
    _scheduler.start()

    log.info(
        "scheduler_started",
        jobs=[job.id for job in _scheduler.get_jobs()],
    )

    # 8. Block main thread — APScheduler runs in background threads
    try:
        while True:
            time.sleep(60)
            log.debug(
                "scheduler_heartbeat",
                running_jobs=[j.id for j in _scheduler.get_jobs()],
            )
    except (KeyboardInterrupt, SystemExit):
        _shutdown(signal.SIGINT, None)


if __name__ == "__main__":
    main()
