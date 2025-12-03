#!/usr/bin/env python3
import asyncio
import json
import statistics
import logging
import signal
import time
import argparse
import os
from dataclasses import asdict, dataclass
from datetime import datetime
from typing import Dict, List, Optional, Tuple
from dotenv import load_dotenv
from pathlib import Path

# Load environment variables from .env file in project root
project_root = Path(__file__).resolve().parent.parent.parent
dotenv_path = project_root / '.env'
load_dotenv(dotenv_path=dotenv_path)

# Async MySQL & Modbus
import aiomysql
from pymodbus.client import AsyncModbusTcpClient
from pymodbus.exceptions import ModbusException
from scipy.signal import find_peaks

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s | %(levelname)-8s | %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("DWP")

# ----------------------------
# CONFIGURATION (Update These!)
# ----------------------------
# Polling
POLL_INTERVAL_SEC = 0.1  # 10 Hz → recommended for press cycles
MODBUS_TIMEOUT_SEC = 1.0
MODBUS_PORT = 503
MODBUS_UNIT_ID = 1

# MySQL
DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": int(os.getenv("DB_PORT", "3306")),
    "user": os.getenv("DB_USERNAME"),
    "password": os.getenv("DB_PASSWORD"),
    "db": os.getenv("DB_DATABASE"),
    "charset": "utf8mb4",
    "autocommit": True,  # Critical for performance!
    "maxsize": 10,  # Connection pool size
}

# Cycle detection
CYCLE_START_THRESHOLD = 1
CYCLE_END_THRESHOLD = 2
MIN_CYCLE_DURATION_MS = 200
MAX_BUFFER_LENGTH = 500
CYCLE_TIMEOUT_SEC = 30

# Minimum accepted cycle duration (seconds). Cycles shorter than this are ignored.
MIN_DURATION_S = 8

# Split / peak tuning (tweak these for your machine)
SPLIT_MIN_SAMPLES = 5
SPLIT_MIN_ZERO_GAP = 3
SPLIT_PEAK_DISTANCE = 3

# Quality thresholds
GOOD_MIN, GOOD_MAX = 30, 45
EXTENDED_MIN, EXTENDED_MAX = 25, 55
MARGINAL_MIN, MARGINAL_MAX = 15, 70
SENSOR_LOW = 10
PRESSURE_HIGH = 80


# ----------------------------
# DATA MODELS
# ----------------------------
@dataclass
class MachineConfig:
    name: str
    addr_th_l: int
    addr_th_r: int
    addr_side_l: int
    addr_side_r: int


@dataclass
class DeviceConfig:
    id: int
    name: str
    ip: str
    lines: Dict[str, List[MachineConfig]]


# ----------------------------
# HELPER FUNCTIONS
# ----------------------------
def is_good(value: int) -> bool:
    return GOOD_MIN <= value <= GOOD_MAX


def is_in_range(value: int, low: int, high: int) -> bool:
    return low <= value <= high


def determine_quality(max_th: int, max_side: int, cycle_type: str = "COMPLETE") -> str:
    if cycle_type in ("SHORT_CYCLE", "OVERFLOW", "TIMEOUT"):
        return cycle_type

    # EXCELLENT: both in perfect range
    if is_good(max_th) and is_good(max_side):
        return "EXCELLENT"

    # GOOD: both in extended range
    if is_in_range(max_th, EXTENDED_MIN, EXTENDED_MAX) and is_in_range(
        max_side, EXTENDED_MIN, EXTENDED_MAX
    ):
        return "GOOD"

    # MARGINAL: one good, one marginal
    th_good = is_good(max_th)
    side_good = is_good(max_side)
    th_marginal = is_in_range(max_th, MARGINAL_MIN, MARGINAL_MAX)
    side_marginal = is_in_range(max_side, MARGINAL_MIN, MARGINAL_MAX)
    if (th_good and side_marginal) or (side_good and th_marginal):
        return "MARGINAL"

    # Sensor/pressure issues
    if max_th < SENSOR_LOW and max_side < SENSOR_LOW:
        return "SENSOR_LOW"
    if max_th > PRESSURE_HIGH or max_side > PRESSURE_HIGH:
        return "PRESSURE_HIGH"

    return "DEFECTIVE"


def extract_machine_id(name: str) -> int:
    # Extract digits from "mc2", "machine_5", etc.
    digits = "".join(filter(str.isdigit, name))
    return int(digits) if digits else 0


async def maybe_await(obj):
    """Await obj if it's awaitable (coroutine or Future). Otherwise return immediately."""
    try:
        if asyncio.iscoroutine(obj) or asyncio.isfuture(obj):
            await obj
    except Exception:
        # best-effort: if awaiting fails, ignore to avoid blocking shutdown
        pass


# ----------------------------
# MYSQL DATABASE MANAGER
# ----------------------------
class DatabaseManager:
    def __init__(self, config: dict):
        self.config = config
        self.pool: Optional[aiomysql.Pool] = None

    async def connect(self):
        self.pool = await aiomysql.create_pool(**self.config)
        logger.info("✅ MySQL pool created")

    async def close(self):
        if self.pool:
            self.pool.close()
            await self.pool.wait_closed()
            logger.info("👋 MySQL pool closed")

    async def save_cycle(self, cycle_data: dict) -> bool:
        if not self.pool:
            logger.error("❌ DB pool not initialized")
            return False

        try:
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cur:
                    # Get last count for line
                    await cur.execute(
                        "SELECT `count` FROM `ins_dwp_counts` WHERE `line` = %s ORDER BY `id` DESC LIMIT 1",
                        (cycle_data["line"],),
                    )
                    result = await cur.fetchone()
                    new_count = (result[0] if result else 0) + 1

                    # Build JSON fields
                    pv_data = {
                        "waveforms": [
                            cycle_data["th_waveform"],
                            cycle_data["side_waveform"],
                        ],
                        # optional per-sample timestamps (epoch ms)
                        **({"timestamps": cycle_data.get("timestamps")} if cycle_data.get("timestamps") is not None else {}),
                        "quality": {
                            "grade": cycle_data["quality_grade"],
                            "peaks": {
                                "th": cycle_data["max_th"],
                                "side": cycle_data["max_side"],
                            },
                            "cycle_type": cycle_data["cycle_type"],
                            "sample_count": cycle_data["sample_count"],
                        },
                    }
                    std_error = [
                        [1 if GOOD_MIN <= cycle_data["max_th"] <= GOOD_MAX else 0],
                        [1 if GOOD_MIN <= cycle_data["max_side"] <= GOOD_MAX else 0],
                    ]

                    # ✅ INSERT WITHOUT created_at/updated_at — let MySQL auto-fill!
                    await cur.execute(
                        """
                        INSERT INTO `ins_dwp_counts` (
                            `line`, `mechine`, `count`, `incremental`, `position`,
                            `pv`, `duration`, `std_error`
                        ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                    """,
                        (
                            cycle_data["line"],
                            cycle_data["machine"],
                            new_count,
                            1,  # incremental
                            cycle_data["position"],
                            json.dumps(pv_data, separators=(",", ":")),
                            cycle_data.get("duration_s", None),  # stored in seconds
                            json.dumps(std_error, separators=(",", ":")),
                        ),
                    )
                    return True
        except Exception as e:
            logger.error(f"❌ DB save failed: {e}")
            return False


# ----------------------------
# MAIN POLLER CLASS
# ----------------------------
class DWPPoller:
    def __init__(self, poll_only_machine: Optional[str] = None):
        """poll_only_machine: if set (e.g. 'mc1'), only poll that machine across all lines/devices."""
        self.devices: Dict[int, DeviceConfig] = {}
        self.clients: Dict[int, AsyncModbusTcpClient] = {}
        self.cycle_states: Dict[str, dict] = {}
        self.db = DatabaseManager(DB_CONFIG)
        self.running = True
        self.shutdown_event = asyncio.Event()
        # optional: only poll a single machine name (e.g., 'mc1')
        self.poll_only_machine: Optional[str] = poll_only_machine

    async def load_devices(self):
        """✅ REPLACE THIS WITH REAL DB QUERY (e.g., SELECT * FROM ins_dwp_devices WHERE active=1)"""
        # Example mock device — update with your actual config
        self.devices = {
            1: DeviceConfig(
                id=1,
                name="Press-G5",
                ip="172.70.87.35",
                lines={
                    "G5": [
                        MachineConfig("mc1", 199, 201, 200, 202),
                        MachineConfig("mc2", 203, 205, 204, 206),
                        MachineConfig("mc3", 309, 311, 310, 312),
                        MachineConfig("mc4", 313, 315, 314, 316),
                    ]
                },
            )
        }
        logger.info(f"✅ Loaded {len(self.devices)} active device(s)")

    async def connect_clients(self):
        for dev_id, dev in self.devices.items():
            client = AsyncModbusTcpClient(
                dev.ip, port=MODBUS_PORT, timeout=MODBUS_TIMEOUT_SEC
            )
            await client.connect()
            if client.connected:
                self.clients[dev_id] = client
                logger.info(f"🔌 Connected to {dev.name} ({dev.ip})")
            else:
                logger.error(f"❌ Failed to connect to {dev.name} ({dev.ip})")

    async def read_registers(
        self, client: AsyncModbusTcpClient, addresses: List[int]
    ) -> List[int]:
        try:
            start_addr = min(addresses)
            count = max(addresses) - start_addr + 1
            response = await client.read_input_registers(
                address=start_addr, count=count, slave=MODBUS_UNIT_ID
            )
            if response.isError():
                raise ModbusException(f"Modbus error: {response}")

            # Map back to requested addresses
            values = []
            for addr in addresses:
                idx = addr - start_addr
                values.append(
                    response.registers[idx] if idx < len(response.registers) else 0
                )
            return values
        except Exception as e:
            logger.error(f"Modbus read failed: {e}")
            raise

    async def poll_machine(
        self,
        dev: DeviceConfig,
        client: AsyncModbusTcpClient,
        line: str,
        machine: MachineConfig,
    ):
        key_l = f"{line}-{machine.name}-L"
        key_r = f"{line}-{machine.name}-R"

        addrs = [
            machine.addr_th_l,
            machine.addr_th_r,
            machine.addr_side_l,
            machine.addr_side_r,
        ]
        try:
            vals = await self.read_registers(client, addrs)
            th_l, th_r, side_l, side_r = vals
        except:
            return

        await self.process_position(line, machine.name, "L", th_l, side_l, key_l)
        await self.process_position(line, machine.name, "R", th_r, side_r, key_r)

    async def process_position(
        self, line: str, machine_name: str, pos: str, th: int, side: int, key: str
    ):
        now = time.time()
        state = self.cycle_states.setdefault(key, {"state": "idle"})

        # Timeout reset — if a cycle runs too long, save as TIMEOUT (best-effort)
        if (
            state["state"] != "idle"
            and (now - state.get("start_time", 0)) > CYCLE_TIMEOUT_SEC
        ):
            logger.warning(f"⏱️  Cycle {key} timed out — saving as TIMEOUT and resetting")
            # try to save whatever we have as a TIMEOUT cycle
            try:
                elapsed_ms = int((now - state.get("start_time", now)) * 1000)
                await self.save_cycle_to_db(line, machine_name, pos, state, elapsed_ms, "TIMEOUT")
            except Exception as e:
                logger.error(f"❌ Failed to save TIMEOUT cycle {key}: {e}")
            state["state"] = "idle"

        # State machine
        if state["state"] == "idle":
            if th >= CYCLE_START_THRESHOLD or side >= CYCLE_START_THRESHOLD:
                state.update(
                    {
                        "state": "active",
                        "start_time": now,
                        "last_nonzero": now,
                        "th_buf": [th],
                        "side_buf": [side],
                        "t_buf": [now],  # per-sample epoch timestamps (seconds)
                    }
                )
                logger.debug(f"🟢 START {key}: TH={th}, Side={side}")

        elif state["state"] == "active":
            state["th_buf"].append(th)
            state["side_buf"].append(side)
            # record timestamp for each sample
            state.setdefault("t_buf", []).append(now)

            # Update last nonzero time if above threshold
            if th > CYCLE_END_THRESHOLD or side > CYCLE_END_THRESHOLD:
                state["last_nonzero"] = now

            elapsed_ms = (now - state["start_time"]) * 1000

            # End condition: 500ms of zeros + min duration
            if (
                now - state["last_nonzero"]
            ) >= 0.5 and elapsed_ms >= MIN_CYCLE_DURATION_MS:
                await self.save_cycle_to_db(
                    line, machine_name, pos, state, int(elapsed_ms)
                )
                state["state"] = "idle"

            # Buffer overflow
            if len(state["th_buf"]) > MAX_BUFFER_LENGTH:
                logger.warning(f"⚠️ Buffer overflow {key} — saving as OVERFLOW")
                await self.save_cycle_to_db(
                    line, machine_name, pos, state, int(elapsed_ms), "OVERFLOW"
                )
                state["state"] = "idle"

    # ----------------------------
    # NEW: WAVEFORM VALIDATION
    # ----------------------------
    def validate_waveform_sanity(
        self,
        th_waveform: List[int],
        side_waveform: List[int],
        sample_count: int,
        duration_ms: int,
        position: str,
        timestamps_ms: Optional[List[int]] = None,
    ) -> Tuple[bool, str]:
        """
        Returns (is_valid, reason_if_invalid)
        Flags physically implausible waveforms.
        """
        if not th_waveform or not side_waveform:
            return False, "Empty waveform"

        if len(th_waveform) != len(side_waveform):
            return False, "TH/Side length mismatch"

        max_th = max(th_waveform)
        max_side = max(side_waveform)
        min_th = min(th_waveform)
        min_side = min(side_waveform)

        # -------------------------
        # 1. Side pressure near-zero while TH is high → sensor fault
        #    In split cycles, allow *brief* side drop, but not entire flat zero
        # -------------------------
        if max_th >= 30 and max_side <= 3:
            nonzero_side = sum(1 for v in side_waveform if v > 5)
            zero_side_ratio = (len(side_waveform) - nonzero_side) / len(side_waveform)
            if zero_side_ratio > 0.8:  # >80% zeros → likely sensor disconnected
                return (
                    False,
                    f"Side sensor likely disconnected: TH={max_th}, Side max={max_side}, {zero_side_ratio:.0%} zeros",
                )

        # -------------------------
        # 2. Extreme Δ/dt (jumps > 30 in one 100ms sample)
        # -------------------------
        for i in range(1, len(th_waveform)):
            dth = abs(th_waveform[i] - th_waveform[i - 1])
            dside = abs(side_waveform[i] - side_waveform[i - 1])
            if dth > 30 or dside > 30:
                if dth > 40 or dside > 40:
                    return (
                        False,
                        f"Impossible pressure jump: ΔTH={dth}, ΔSide={dside} at sample {i}",
                    )
                # else: log warning but allow (e.g., noise spike)
                logger.debug(
                    f"⚠️ Large pressure jump ΔTH={dth}, ΔSide={dside} at sample {i}"
                )

        # -------------------------
        # 3. Flatline detection
        # -------------------------
        if max_th - min_th <= 1 and max_side - min_side <= 1 and sample_count > 3:
            if max_th == 0 and max_side == 0:
                return False, "Zero flatline — no cycle detected"
            return False, "Flatline waveform — no pressure change"

        # -------------------------
        # 4. Duration vs sample sanity
        # Expected sample interval: prefer measured median interval if
        # per-sample timestamps are available. Otherwise fall back to 100ms.
        # This avoids false "Too few samples" when actual poll interval is
        # slower than the nominal 100ms due to network/IO latency.
        # -------------------------
        median_interval_ms = 100
        if timestamps_ms and len(timestamps_ms) > 1:
            try:
                diffs = [
                    timestamps_ms[i] - timestamps_ms[i - 1]
                    for i in range(1, len(timestamps_ms))
                ]
                # ignore zero diffs if any (defensive)
                diffs = [d for d in diffs if d > 0]
                if diffs:
                    median_interval_ms = max(1, int(statistics.median(diffs)))
            except Exception:
                median_interval_ms = 100

        expected_samples = max(1, round(duration_ms / median_interval_ms))
        if sample_count < 1 or expected_samples == 0:
            return False, "Invalid duration or sample count"
        # Allow sparser buffers now: treat <15% of expected as missed samples
        if sample_count < expected_samples * 0.15:  # <15% expected → missed samples
            return (
                False,
                f"Too few samples: {sample_count} for {duration_ms}ms (expected ~{expected_samples}, median_interval={median_interval_ms}ms)",
            )

        # -------------------------
        # 5. Negative values (shouldn't happen, but guard)
        # -------------------------
        if min_th < 0 or min_side < 0:
            return False, "Negative pressure reading"

        # All passed
        return True, "OK"

    def compute_std_error_flags(
        self,
        th_waveform: List[int],
        side_waveform: List[int],
        max_th: int,
        max_side: int,
    ) -> List[List[int]]:
        """
        Returns [[th_flag], [side_flag]] where 1 = OK, 0 = suspect
        Enhances original logic with waveform-aware checks
        """
        th_flag = 1 if (GOOD_MIN <= max_th <= GOOD_MAX) else 0
        side_flag = 1 if (GOOD_MIN <= max_side <= GOOD_MAX) else 0

        # Side sensor likely failed if TH active but Side flat near zero
        if max_th >= 30 and max_side <= 3:
            nonzero_side = sum(1 for v in side_waveform if v > 5)
            if nonzero_side <= 1:
                side_flag = 0

        # TH sensor likely failed if Side active but TH flat near zero
        if max_side >= 30 and max_th <= 3:
            nonzero_th = sum(1 for v in th_waveform if v > 5)
            if nonzero_th <= 1:
                th_flag = 0

        # Flatline sensors
        if len(set(th_waveform)) == 1 and len(th_waveform) > 2:
            th_flag = 0
        if len(set(side_waveform)) == 1 and len(side_waveform) > 2:
            side_flag = 0

        return [[th_flag], [side_flag]]

    async def save_cycle_to_db(
        self,
        line: str,
        machine_name: str,
        pos: str,
        state: dict,
        duration_ms: int,
        cycle_type: str = "COMPLETE",
    ):
        print("array", state["th_buf"])
        print("array", state["side_buf"])
        th_buf = state["th_buf"]
        side_buf = state["side_buf"]
        t_buf = state.get("t_buf", [])
        # Convert per-sample timestamps to epoch-ms for duration / sanity checks
        timestamps_ms = [int(ts * 1000) for ts in t_buf] if t_buf else []

        # Prefer duration computed from timestamps (more accurate); fall back to provided duration_ms
        if len(timestamps_ms) > 1:
            duration_ms_field = int(timestamps_ms[-1] - timestamps_ms[0])
        else:
            duration_ms_field = int(duration_ms)

        duration_s = duration_ms_field / 1000.0

        # If the entire buffer is shorter than MIN_DURATION_S, skip saving/splitting
        if duration_s < MIN_DURATION_S:
            logger.info(
                f"⏭️ Skipping {line}-{machine_name}-{pos}: total duration {duration_s:.1f}s < MIN_DURATION_S ({MIN_DURATION_S}s) - not saving or splitting"
            )
            return

        # Build combined signal (element-wise max) to detect physical cycle peaks
        combined = [max(a, b) for a, b in zip(th_buf, side_buf)] if th_buf and side_buf else th_buf or side_buf

        # Detect peaks on combined signal so we catch cycles where TH and Side
        # peak at different times or where only one channel is active.
        peaks, _ = find_peaks(combined, height=CYCLE_START_THRESHOLD, distance=SPLIT_PEAK_DISTANCE)
        if len(peaks) > 1:
            logger.info(
                f"ℹ️ Multiple peaks ({len(peaks)}) in {line}-{machine_name}-{pos} — attempting split"
            )
            try:
                saved_count = await self.split_and_save_cycles(
                    line,
                    machine_name,
                    pos,
                    th_buf,
                    side_buf,
                    list(peaks),
                    duration_ms,
                    t_buf,
                )
            except Exception as e:
                logger.error(f"❌ Error splitting cycles: {e}")
                saved_count = 0
            if saved_count and saved_count > 0:
                logger.info(f"✅ Saved {saved_count} split sub-cycles for {line}-{machine_name}-{pos}")
                return

        max_th = max(th_buf) if th_buf else 0
        max_side = max(side_buf) if side_buf else 0
        sample_count = len(th_buf)
        # Precompute std_error so it's always available for logging
        std_error = self.compute_std_error_flags(th_buf, side_buf, max_th, max_side)

        # Convert per-sample timestamps to epoch-ms for storage/visualization
        timestamps_ms = [int(ts * 1000) for ts in t_buf] if t_buf else []

        # Prefer duration computed from timestamps (more accurate); fall back to provided duration_ms
        if len(timestamps_ms) > 1:
            duration_ms_field = int(timestamps_ms[-1] - timestamps_ms[0])
        else:
            # duration_ms param is already in milliseconds from the caller
            duration_ms_field = int(duration_ms)

        # Convert to seconds for storage/visualization (float seconds)
        duration_s = duration_ms_field / 1000.0

        # Skip cycles that are too short to be meaningful
        if duration_s < MIN_DURATION_S:
            logger.info(
                f"⏭️ Skipping {line}-{machine_name}-{pos}: duration {duration_s:.1f}s < MIN_DURATION_S ({MIN_DURATION_S}s)"
            )
            return

        # 🆕 WAVEFORM SANITY CHECK
        is_sane, reason = self.validate_waveform_sanity(
            th_buf, side_buf, sample_count, duration_ms, pos, timestamps_ms
        )
        if not is_sane:
            logger.warning(
                f"❌ Invalid waveform {line}-{machine_name}-{pos}: {reason} | "
                f"TH={th_buf}, Side={side_buf}"
            )
            # Force grade to DEFECTIVE and override cycle_type
            grade = "DEFECTIVE"
            cycle_type = "INVALID_WAVEFORM"
        else:
            grade = determine_quality(max_th, max_side, cycle_type)

        cycle_data = {
            "line": line,
            "machine": extract_machine_id(machine_name),
            "position": pos,
            "th_waveform": th_buf,
            "side_waveform": side_buf,
            "timestamps": timestamps_ms,
            "duration_s": duration_s,
            "quality_grade": grade,
            "max_th": max_th,
            "max_side": max_side,
            "sample_count": sample_count,
            "cycle_type": cycle_type,
        }

        success = await self.db.save_cycle(cycle_data)
        if success:
            logger.info(
                f"✅ {grade} | {line}-{machine_name}-{pos} | "
                    f"samples={sample_count} | {duration_s:.3f}s | "
                    f"TH={max_th}, Side={max_side} | std={std_error}"
            )
        else:
            logger.error(f"❌ Failed to save cycle {line}-{machine_name}-{pos}")

    async def poll_loop(self):
        while self.running:
            start = time.perf_counter()
            for dev in self.devices.values():
                client = self.clients.get(dev.id)
                if not client or not client.connected:
                    continue
                for line, machines in dev.lines.items():
                    for machine in machines:
                        # If configured to poll only one machine, skip others
                        if self.poll_only_machine and machine.name != self.poll_only_machine:
                            continue
                        await self.poll_machine(dev, client, line, machine)
            # Maintain polling frequency
            elapsed = time.perf_counter() - start
            await asyncio.sleep(max(0, POLL_INTERVAL_SEC - elapsed))

    def signal_handler(self, signum, frame):
        logger.info("🛑 Shutdown signal received...")
        self.running = False

    async def run(self):
        # Setup signals
        signal.signal(signal.SIGINT, self.signal_handler)
        signal.signal(signal.SIGTERM, self.signal_handler)

        try:
            await self.db.connect()
            await self.load_devices()
            await self.connect_clients()
            logger.info(f"🚀 DWP Poller started (interval={POLL_INTERVAL_SEC}s)")
            await self.poll_loop()
        finally:
            # Cleanup (best-effort)
            for client in self.clients.values():
                # Some AsyncModbusTcpClient.close() implementations return a coroutine,
                # others are synchronous. Await only when close() is a coroutine.
                if hasattr(client, "close"):
                    try:
                        close_result = client.close()
                        await maybe_await(close_result)
                    except Exception:
                        # Best-effort fallback: try calling close() again and ignore errors
                        try:
                            client.close()
                        except Exception:
                            pass
            await self.db.close()
            logger.info("👋 DWP Poller stopped.")

    async def split_and_save_cycles(
        self,
        line: str,
        machine_name: str,
        pos: str,
        th_buf: List[int],
        side_buf: List[int],
        peaks: List[int],
        total_duration_ms: int,
        t_buf: List[float],
    ):
        """Split multi-peak buffer into individual cycles"""
        # element-wise max of TH/Side to reason about physical gaps
        combined = [max(a, b) for a, b in zip(th_buf, side_buf)] if th_buf and side_buf else th_buf or side_buf

        # helper: check for a run of N consecutive "low" samples in combined between i..j
        def has_min_zero_gap(start_idx: int, end_idx: int, min_gap: int) -> bool:
            if end_idx < start_idx:
                return False
            run = 0
            for k in range(start_idx, end_idx + 1):
                if combined[k] <= CYCLE_END_THRESHOLD:
                    run += 1
                    if run >= min_gap:
                        return True
                else:
                    run = 0
            return False

        saved_count = 0
        prev_peak = None
        for i, peak_idx in enumerate(peaks):
            # If there was a previous peak, require a zero-gap between them to split
            if prev_peak is not None:
                if not has_min_zero_gap(prev_peak + 1, peak_idx - 1, SPLIT_MIN_ZERO_GAP):
                    logger.info(
                        f"⏭️ Peaks {prev_peak} and {peak_idx} too close (no zero-gap) — treating as same cycle"
                    )
                    prev_peak = peak_idx
                    continue
            prev_peak = peak_idx

            # Find start (first above-threshold before peak)
            start_idx = peak_idx
            while start_idx > 0 and combined[start_idx - 1] > CYCLE_END_THRESHOLD:
                start_idx -= 1
            start_idx = max(0, start_idx)

            # Find end (first below-threshold after peak)
            end_idx = peak_idx
            while end_idx < len(combined) - 1 and combined[end_idx + 1] > CYCLE_END_THRESHOLD:
                end_idx += 1
            end_idx = min(len(combined) - 1, end_idx)

            # Extract sub-cycle
            th_sub = th_buf[start_idx : end_idx + 1]
            side_sub = side_buf[start_idx : end_idx + 1]
            # Extract timestamp slice (epoch-ms)
            sub_t_buf = t_buf[start_idx : end_idx + 1] if t_buf else []
            sub_timestamps_ms = [int(ts * 1000) for ts in sub_t_buf] if sub_t_buf else []

            # Calculate duration (prefer timestamps if present)
            # sub_timestamps_ms contains epoch-ms for each sample in the sub-cycle
            if sub_timestamps_ms and len(sub_timestamps_ms) > 1:
                sub_duration_ms = int(sub_timestamps_ms[-1] - sub_timestamps_ms[0])
            else:
                sub_duration_ms = int((end_idx - start_idx + 1) * POLL_INTERVAL_SEC * 1000)

            # store seconds for DB/visualization
            sub_duration_s = sub_duration_ms / 1000.0

            # Skip very short sub-cycles
            if sub_duration_s < MIN_DURATION_S:
                logger.info(
                    f"⏭️ Skipping split sub-cycle {i+1}/{len(peaks)} for {line}-{machine_name}-{pos}: duration {sub_duration_s:.1f}s < {MIN_DURATION_S}s"
                )
                continue

            # Validate sub-cycle waveform sanity; skip invalid ones
            is_sane, reason = self.validate_waveform_sanity(
                th_sub, side_sub, len(th_sub), sub_duration_ms, pos, sub_timestamps_ms
            )
            if not is_sane:
                logger.info(
                    f"⏭️ Skipping split sub-cycle {i+1}/{len(peaks)} for {line}-{machine_name}-{pos}: invalid waveform ({reason})"
                )
                continue

            # Save as individual cycle
            max_th = max(th_sub) if th_sub else 0
            max_side = max(side_sub) if side_sub else 0
            grade = determine_quality(max_th, max_side, "SPLIT")

            # Validation: skip very short sub-cycles (insufficient samples)
            if len(th_sub) < 4:
                logger.info(
                    f"⏭️ Skipping split sub-cycle {i+1}/{len(peaks)} for {line}-{machine_name}-{pos}: too few samples ({len(th_sub)})"
                )
                continue

            cycle_data = {
                "line": line,
                "machine": extract_machine_id(machine_name),
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

            success = await self.db.save_cycle(cycle_data)
            if success:
                logger.info(
                    f"✅ SPLIT Cycle {i + 1}/{len(peaks)} saved for {line}-{machine_name}-{pos}"
                )
                saved_count += 1
        return saved_count

# ----------------------------
# ENTRY POINT
# ----------------------------
if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="DWP Poller")
    parser.add_argument("--machine", "-m", help="Poll only this machine name (e.g., mc1)")
    args = parser.parse_args()

    poller = DWPPoller(poll_only_machine=args.machine)
    asyncio.run(poller.run())
