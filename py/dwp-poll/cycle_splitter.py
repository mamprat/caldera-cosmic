#!/usr/bin/env python3
import logging
from typing import List

# This module contains the extracted split_and_save_cycles helper used by the
# poller. It was moved out of the DWPPoller class so it can be reused and
# unit-tested independently.

logger = logging.getLogger("DWP")


async def split_and_save_cycles(
    db,
    logger_param,
    determine_quality_fn,
    extract_machine_id_fn,
    line: str,
    machine_name: str,
    pos: str,
    th_buf: List[int],
    side_buf: List[int],
    peaks: List[int],
    total_duration_ms: int,
    t_buf: List[float],
):
    """Split a multi-peak buffer into individual cycles and save each
    sub-cycle using the provided `db` manager. The function accepts helper
    callables for `determine_quality` and `extract_machine_id` so it remains
    decoupled from the poller class.

    Args:
        db: DatabaseManager-like object with async `save_cycle(dict)` method
        logger_param: logger to use for events
        determine_quality_fn: callable(max_th, max_side, cycle_type) -> str
        extract_machine_id_fn: callable(machine_name) -> int
        line, machine_name, pos: identifiers
        th_buf, side_buf: full buffers
        peaks: list of peak indices in th_buf
        total_duration_ms: total buffer duration estimate (ms)
        t_buf: per-sample epoch timestamps (seconds)
    """
    log = logger_param or logger

    for i, peak_idx in enumerate(peaks):
        # Find start (first non-zero before peak)
        start_idx = peak_idx
        while start_idx > 0 and th_buf[start_idx - 1] <= 2:
            start_idx -= 1
        start_idx = max(0, start_idx)

        # Find end (first zero after peak)
        end_idx = peak_idx
        while end_idx < len(th_buf) - 1 and th_buf[end_idx + 1] > 2:
            end_idx += 1
        end_idx = min(len(th_buf) - 1, end_idx)

        # Extract sub-cycle
        th_sub = th_buf[start_idx : end_idx + 1]
        side_sub = side_buf[start_idx : end_idx + 1]
        sub_t_buf = t_buf[start_idx : end_idx + 1] if t_buf else []
        sub_timestamps_ms = [int(ts * 1000) for ts in sub_t_buf] if sub_t_buf else []

        # Calculate duration (prefer timestamps if present)
        if sub_timestamps_ms and len(sub_timestamps_ms) > 1:
            sub_duration_ms = int(sub_timestamps_ms[-1] - sub_timestamps_ms[0])
        else:
            # fallback to sample-count-based estimate assuming poll interval
            sub_duration_ms = int((end_idx - start_idx + 1) * 0.1 * 1000)

        sub_duration_s = sub_duration_ms / 1000.0

        # Save as individual cycle
        max_th = max(th_sub) if th_sub else 0
        max_side = max(side_sub) if side_sub else 0
        grade = determine_quality_fn(max_th, max_side, "SPLIT")

        # Validation: skip very short sub-cycles (insufficient samples)
        if len(th_sub) < 4:
            log.info(
                f"⏭️ Skipping split sub-cycle {i+1}/{len(peaks)} for {line}-{machine_name}-{pos}: too few samples ({len(th_sub)})"
            )
            continue

        cycle_data = {
            "line": line,
            "machine": extract_machine_id_fn(machine_name),
            "position": pos,
            "th_waveform": th_sub,
            "side_waveform": side_sub,
            "timestamps": sub_timestamps_ms,
            "duration_s": sub_duration_s,
            "quality_grade": grade,
            "max_th": max_th,
            "max_side": max_side,
            "sample_count": len(th_sub),
            "cycle_type": "SPLIT",
        }

        try:
            success = await db.save_cycle(cycle_data)
            if success:
                log.info(f"✅ SPLIT Cycle {i+1}/{len(peaks)} saved for {line}-{machine_name}-{pos}")
        except Exception as e:
            log.error(f"❌ Failed saving split cycle {line}-{machine_name}-{pos}: {e}")
