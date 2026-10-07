"""The evaluator service: a spool-directory worker with no network.

Laravel writes ``requests/<id>.json`` (atomically, by rename). The service
claims a request by renaming it into ``work/``, runs it in a free sandbox
slot and writes ``results/<id>.json`` (atomically). A request is executed at
most once: a claimed request whose result was never written (the service
stopped mid-run) becomes an ``INTERRUPTED`` result at the next start, never
a second run.

The spool is shared with Laravel through a volume whose directories belong
to the spool group only; sandbox users are not in that group.
"""

from __future__ import annotations

import json
import logging
import os
import signal
import time
from concurrent.futures import Future, ThreadPoolExecutor
from pathlib import Path
from typing import Any

from evaluator import VERSION, protocol, sandbox
from evaluator import config as configuration
from evaluator.config import Config, Slot

SPOOL_GID = 10500
log = logging.getLogger("codedna.evaluator")


def prepare_spool(spool: Path) -> None:
    for directory in (spool, spool / "requests", spool / "work", spool / "results"):
        directory.mkdir(exist_ok=True)
        os.chown(directory, -1, SPOOL_GID)
        # Owner and spool group only; nobody else, sandbox users included.
        os.chmod(directory, 0o2770)  # noqa: S103 - not world-accessible


def write_result(spool: Path, record: dict[str, Any]) -> None:
    final = spool / "results" / f"{record['id']}.json"
    temporary = spool / "results" / f".tmp-{record['id']}"
    temporary.write_text(json.dumps(record, sort_keys=True), encoding="utf-8")
    os.chmod(temporary, 0o640)
    os.replace(temporary, final)


def recover(spool: Path) -> int:
    """Claimed but unfinished requests from a previous run: report, never re-run."""
    recovered = 0
    for claimed in sorted((spool / "work").glob("*.json")):
        request_id = claimed.stem
        if protocol.ID.match(request_id):
            write_result(spool, protocol.result(request_id, "INTERRUPTED"))
            recovered += 1
        claimed.unlink(missing_ok=True)
    return recovered


def evaluate(slot: Slot, conf: Config, request: dict[str, Any]) -> dict[str, Any]:
    limits = conf.limits
    inspected = sandbox.run(slot, limits, {"mode": "inspect", "source": request["source"]})
    records = protocol.parse_records(inspected.lines)
    inspection_record = next((r for r in records if r["type"] == "inspection"), None)
    inspection = protocol.sanitize_inspection(inspection_record)
    duration = inspected.duration_ms
    if inspection_record is None:
        status = "TIMEOUT" if inspected.timed_out else "CRASHED"
        return protocol.result(request["id"], status, inspection=inspection, duration_ms=duration)
    if not inspection["syntax_valid"]:
        return protocol.result(request["id"], "SYNTAX_ERROR", inspection=inspection, duration_ms=duration)

    executed = sandbox.run(
        slot,
        limits,
        {"mode": "run", "source": request["source"], "entrypoint": request["entrypoint"], "cases": request["cases"]},
    )
    records = protocol.parse_records(executed.lines)
    case_ids = [case["id"] for case in request["cases"]]
    load = next((r for r in records if r["type"] == "load"), None)
    finished = any(r["type"] == "done" for r in records)
    load_error = None
    if executed.timed_out:
        status = "TIMEOUT"
    elif executed.output_truncated:
        status = "OUTPUT_LIMIT"
    elif load is not None and load.get("ok") is not True:
        status = "LOAD_ERROR"
        error = load.get("error")
        load_error = error[:64] if isinstance(error, str) else "Error"
    elif not finished:
        status = "CRASHED"
    else:
        status = "COMPLETED"
    return protocol.result(
        request["id"],
        status,
        load_error=load_error,
        inspection=inspection,
        cases=protocol.case_results(case_ids, records),
        duration_ms=duration + executed.duration_ms,
    )


def process(claimed: Path, slot: Slot, conf: Config) -> str:
    request_id = claimed.stem
    try:
        raw = claimed.read_bytes()
        try:
            request = protocol.parse_request(raw, conf.limits)
            if request["id"] != request_id:
                raise protocol.RejectedRequestError("id_mismatch")
            record = evaluate(slot, conf, request)
        except protocol.RejectedRequestError as rejected:
            log.warning("evaluation.rejected id=%s reason=%s", request_id, rejected)
            record = protocol.result(request_id, "REJECTED", load_error=str(rejected)[:64])
        write_result(conf.spool, record)
        log.info(
            "evaluation.completed id=%s slot=%s status=%s duration_ms=%s", request_id, slot.index, record["status"], record["duration_ms"]
        )
        return str(record["status"])
    finally:
        claimed.unlink(missing_ok=True)


def heartbeat(spool: Path) -> None:
    beat = spool / ".heartbeat.tmp"
    beat.write_text(json.dumps({"at": int(time.time()), "version": VERSION}), encoding="utf-8")
    os.chmod(beat, 0o640)
    os.replace(beat, spool / "heartbeat")


def serve(conf: Config) -> None:
    prepare_spool(conf.spool)
    for slot in conf.slots:
        if not slot.directory.is_dir():
            raise configuration.ConfigError(f"sandbox directory {slot.directory} is missing")
        sandbox.kill_slot_processes(slot.uid)
    log.info("evaluator.started slots=%s recovered=%s", len(conf.slots), recover(conf.spool))

    stopping = {"flag": False}
    signal.signal(signal.SIGTERM, lambda *_: stopping.update(flag=True))
    free: list[Slot] = list(conf.slots)
    running: dict[Future[str], Slot] = {}
    with ThreadPoolExecutor(max_workers=len(conf.slots)) as pool:
        while not stopping["flag"]:
            heartbeat(conf.spool)
            for future in [f for f in running if f.done()]:
                free.append(running.pop(future))
                if future.exception() is not None:
                    log.error("evaluation.crashed error=%s", type(future.exception()).__name__)
            for request in sorted((conf.spool / "requests").glob("*.json")):
                if not free:
                    break
                if not protocol.ID.match(request.stem):
                    request.unlink(missing_ok=True)
                    continue
                claimed = conf.spool / "work" / request.name
                try:
                    os.rename(request, claimed)
                except FileNotFoundError:
                    continue
                slot = free.pop(0)
                running[pool.submit(process, claimed, slot, conf)] = slot
            time.sleep(conf.poll_seconds)


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    serve(configuration.load())


if __name__ == "__main__":
    main()
