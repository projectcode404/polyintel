#!/usr/bin/env python3
"""
Run collectors and report results
"""
import sys
sys.path.insert(0, "/app")

from collectors.snapshots_collector import SnapshotsCollector
from collectors.signal_collector import SignalCollector
from utils.logger import get_logger

log = get_logger(__name__)

print("=" * 80)
print("RUNNING DATA COLLECTION PIPELINE")
print("=" * 80)

# Step 1: Snapshots
print("\nStep 1: Collecting snapshots...")
snapshots_collector = SnapshotsCollector()
result = snapshots_collector.run()

print(f"\n✓ Snapshots collected:")
print(f"  Written: {result.snapshots_written}")
print(f"  Skipped: {result.snapshots_skipped}")
print(f"  Failed: {result.markets_failed}")
print(f"  Success rate: {result.success_rate:.1%}")
snapshots_collector.close()

# Step 2: Signals
print("\n" + "=" * 80)
print("Step 2: Generating signals...")
signal_collector = SignalCollector()
signal_result = signal_collector.run()

print(f"\n✓ Signals generated:")
print(f"  Signals created: {signal_result.signals_created}")
print(f"  Signals updated: {signal_result.signals_updated}")
print(f"  Signals skipped: {signal_result.signals_skipped}")
print(f"  Errors: {signal_result.errors}")
signal_collector.close()

print("\n" + "=" * 80)
print("PIPELINE COMPLETE")
print("=" * 80)
