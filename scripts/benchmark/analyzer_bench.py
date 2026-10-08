#!/usr/bin/env python3
"""Analyzer benchmark (Phase 26, docs/performance/benchmarking.md#analyzer).

Runs the analyzer's own pipeline (app.services.analysis.analyze, the code the
service runs per request) on generated archives, inside a container started
from the analyzer image with the service's CPU and memory limits:

  make benchmark-analyzer

Reports, per archive profile: the time of each stage (extraction, discovery,
parsing, metrics/result building, serialization), peak RSS, and result size;
then throughput, latency and peak RSS at increasing concurrency (threads in
one process, as the service runs analyses: a thread pool bounded by
ANALYZER_MAX_CONCURRENCY). Only the download is replaced (the archive is read
from disk): network time to object storage is not the analyzer's cost.
No security or resource limit is changed.

The "process pool" section is a REFERENCE measurement only: the service
runs analyses in threads, and keeps doing so, because its execution-safety
control forbids multiprocessing and pickle in application code
(tests/test_execution_safety.py). It quantifies what that control costs on
CPU-bound parsing (docs/performance/queue-performance.md#analyzer).

  python analyzer_bench.py <sources-dir> [--profiles small,medium,large,xlarge]
                           [--concurrency 1,2,3,4] [--repeat 3] [--json out.json]
"""

from __future__ import annotations

import argparse
import hashlib
import json
import multiprocessing
import os
import resource
import shutil
import statistics
import subprocess
import sys
import tempfile
import threading
import time
from concurrent.futures import ProcessPoolExecutor, ThreadPoolExecutor
from pathlib import Path
from typing import Any

sys.path.insert(0, os.environ.get("ANALYZER_ROOT", "/app"))

from app import canonical
from app.config import Settings
from app.contracts.request import AnalyzeRequest
from app.services import analysis

RUN_ID = "01k6p0a1b2c3d4e5f6g7h8j9km"


def url() -> str:
    # The shape of a real pre-signed URL (the URL policy checks it), signed "now".
    stamp = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
    return (
        "http://minio:9000/codedna/projects/01k6p0a1b2c3d4e5f6g7h8j9pq/snapshots/01k6p0a1b2c3d4e5f6g7h8j9rs/source.zip"
        f"?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=bench%2F{stamp[:8]}%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date={stamp}"
        "&X-Amz-Expires=900&X-Amz-SignedHeaders=host&X-Amz-Signature=" + "ab" * 32
    )


def settings(workspace: str) -> Settings:
    # The service's own configuration (limits from the environment); only the
    # workspace and the HMAC secret (unused here) are local to the benchmark.
    env = dict(os.environ)
    env["ANALYZER_WORKSPACE_ROOT"] = workspace
    env.setdefault("ANALYZER_HMAC_SECRET", "b" * 64)
    return Settings.from_env(env)


def request(archive: Path) -> AnalyzeRequest:
    data = archive.read_bytes()
    return AnalyzeRequest.model_validate(
        {
            "contract_version": "1.0",
            "analysis_run_id": RUN_ID,
            "attempt": 1,
            "source": {
                "type": "archive",
                "format": "zip",
                "url": url(),
                "sha256": hashlib.sha256(data).hexdigest(),
                "size_bytes": len(data),
            },
            "options": {"result_type": "static_analysis"},
        }
    )


def fetcher_for(archive: Path):  # type: ignore[no-untyped-def]
    def fetch(target, destination, req, conf, deadline) -> None:  # type: ignore[no-untyped-def]
        shutil.copyfile(archive, destination)

    return fetch


def resolve(host: str, port: int) -> list[str]:
    return ["10.0.0.10"]


STAGES = ("extract_archive", "discover", "parse_files", "build_result", "build_static_analysis_result")


def stage_timings(archive: Path, workspace: str) -> dict[str, Any]:
    """One analysis with per-stage timings (stages wrapped in place)."""
    timings: dict[str, float] = {}
    originals = {name: getattr(analysis, name) for name in STAGES}

    def wrap(name: str, function):  # type: ignore[no-untyped-def]
        def timed(*args, **kwargs):  # type: ignore[no-untyped-def]
            started = time.perf_counter()
            try:
                return function(*args, **kwargs)
            finally:
                timings[name] = timings.get(name, 0.0) + (time.perf_counter() - started) * 1000

        return timed

    for name, function in originals.items():
        setattr(analysis, name, wrap(name, function))
    try:
        started = time.perf_counter()
        result = analysis.analyze(request(archive), settings(workspace), resolver=resolve, fetcher=fetcher_for(archive))
        total = (time.perf_counter() - started) * 1000
        started = time.perf_counter()
        body = canonical.canonical_json(result)
        timings["serialize"] = (time.perf_counter() - started) * 1000
    finally:
        for name, function in originals.items():
            setattr(analysis, name, function)
    return {
        "total_ms": total + timings["serialize"],
        "stages_ms": timings,
        "result_bytes": len(body),
        "files": result["source"]["files_analyzed"],
    }


def child(archive: str, workspace: str) -> None:
    """Runs in a fresh process: one analysis, then its peak RSS."""
    baseline = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
    measured = stage_timings(Path(archive), workspace)
    measured["peak_rss_mb"] = round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024, 1)
    measured["baseline_rss_mb"] = round(baseline / 1024, 1)
    print(json.dumps(measured))


def _process_one(archive: str, workspace: str) -> float:
    """One analysis in a pool process (module level, so it can be pickled)."""
    started = time.perf_counter()
    analysis.analyze(request(Path(archive)), settings(workspace), resolver=resolve, fetcher=_copy_fetcher)
    return (time.perf_counter() - started) * 1000


def _copy_fetcher(target, destination, req, conf, deadline) -> None:  # type: ignore[no-untyped-def]
    shutil.copyfile(os.environ["BENCH_ARCHIVE"], destination)


def concurrency_processes(archive: Path, workspace: str, workers: int, analyses: int) -> dict[str, Any]:
    """The same analyses in a process pool (one analysis per process at a time)."""
    os.environ["BENCH_ARCHIVE"] = str(archive)
    context = multiprocessing.get_context("spawn")
    with ProcessPoolExecutor(max_workers=workers, mp_context=context) as pool:
        list(pool.map(_process_one, [str(archive)] * workers, [workspace] * workers))  # start and warm the workers
        started = time.perf_counter()
        latencies = sorted(pool.map(_process_one, [str(archive)] * analyses, [workspace] * analyses))
        wall = time.perf_counter() - started
    pick = lambda p: round(latencies[min(len(latencies) - 1, max(0, round(p * len(latencies)) - 1))], 1)
    return {
        "workers": workers,
        "analyses": analyses,
        "failures": 0,
        "throughput_per_min": round(len(latencies) / wall * 60, 1),
        "p50_ms": pick(0.5),
        "p95_ms": pick(0.95),
        "wall_s": round(wall, 2),
    }


def concurrency(archive: Path, workspace: str, threads: int, analyses: int) -> dict[str, Any]:
    req = request(archive)
    conf = settings(workspace)
    latencies: list[float] = []
    failures = 0
    lock = threading.Lock()

    def one(_: int) -> None:
        nonlocal failures
        started = time.perf_counter()
        try:
            analysis.analyze(req, conf, resolver=resolve, fetcher=fetcher_for(archive))
        except Exception:  # noqa: BLE001 - a benchmark counts failures, it does not stop on them
            with lock:
                failures += 1
            return
        with lock:
            latencies.append((time.perf_counter() - started) * 1000)

    started = time.perf_counter()
    with ThreadPoolExecutor(max_workers=threads) as pool:
        list(pool.map(one, range(analyses)))
    wall = time.perf_counter() - started
    latencies.sort()
    pick = lambda p: round(latencies[min(len(latencies) - 1, max(0, round(p * len(latencies)) - 1))], 1) if latencies else None
    return {
        "threads": threads,
        "analyses": analyses,
        "failures": failures,
        "throughput_per_min": round(len(latencies) / wall * 60, 1),
        "p50_ms": pick(0.5),
        "p95_ms": pick(0.95),
        "wall_s": round(wall, 2),
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("sources", type=Path, nargs="?")
    parser.add_argument("--profiles", default="small,medium,large,xlarge")
    parser.add_argument("--concurrency", default="1,2,3,4")
    parser.add_argument("--concurrency-profile", default="medium")
    parser.add_argument("--repeat", type=int, default=3)
    parser.add_argument("--json", type=Path)
    parser.add_argument("--child", nargs=2, metavar=("ARCHIVE", "WORKSPACE"))
    args = parser.parse_args()
    if args.child:
        child(*args.child)
        return

    workspace = tempfile.mkdtemp(prefix="bench-", dir=os.environ.get("BENCH_WORKSPACE", "/tmp/codedna"))
    report: dict[str, Any] = {"cpus": os.cpu_count(), "profiles": {}, "concurrency": []}
    print(
        f"{'profile':8} {'files':>6} {'total ms':>9} {'extract':>8} {'discover':>8} {'parse':>8} {'build':>8} {'serialize':>9} {'result KB':>9} {'peak RSS MB':>11}"
    )
    for profile in args.profiles.split(","):
        archive = args.sources / f"{profile}.zip"
        runs = []
        for _ in range(args.repeat):
            out = subprocess.run(
                [sys.executable, __file__, "--child", str(archive), workspace], capture_output=True, text=True, check=False
            )
            if out.returncode != 0:
                sys.exit(f"{profile}: analysis failed\n{out.stderr[-2000:]}")
            runs.append(json.loads(out.stdout.strip().splitlines()[-1]))
        median = lambda key, runs=runs: statistics.median(r["stages_ms"].get(key, 0.0) for r in runs)
        summary = {
            "files": runs[0]["files"],
            "total_ms": round(statistics.median(r["total_ms"] for r in runs), 1),
            "stages_ms": {stage: round(median(stage), 1) for stage in (*STAGES, "serialize")},
            "result_bytes": runs[0]["result_bytes"],
            "peak_rss_mb": max(r["peak_rss_mb"] for r in runs),
            "baseline_rss_mb": min(r["baseline_rss_mb"] for r in runs),
        }
        report["profiles"][profile] = summary
        s = summary["stages_ms"]
        print(
            f"{profile:8} {summary['files']:>6} {summary['total_ms']:>9} {s['extract_archive']:>8} {s['discover']:>8} {s['parse_files']:>8} "
            f"{s['build_result'] + s['build_static_analysis_result']:>8.1f} {s['serialize']:>9} {summary['result_bytes'] // 1024:>9} {summary['peak_rss_mb']:>11}"
        )

    archive = args.sources / f"{args.concurrency_profile}.zip"
    print(f"\nconcurrency ({args.concurrency_profile}): threads, analyses, failures, throughput/min, p50 ms, p95 ms")
    for threads in (int(t) for t in args.concurrency.split(",")):
        row = concurrency(archive, workspace, threads, max(8, threads * 4))
        row["peak_rss_mb"] = round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024, 1)
        report["concurrency"].append(row)
        print(
            f"  {row['threads']:>2} {row['analyses']:>3} {row['failures']:>2} {row['throughput_per_min']:>8} {row['p50_ms']:>8} {row['p95_ms']:>8}  (process peak RSS {row['peak_rss_mb']} MB)"
        )
    print(
        f"\nreference only, not used by the service: process pool ({args.concurrency_profile}): workers, analyses, failures, throughput/min, p50 ms, p95 ms"
    )
    report["processes"] = []
    for workers in (int(t) for t in args.concurrency.split(",")):
        row = concurrency_processes(archive, workspace, workers, max(8, workers * 4))
        report["processes"].append(row)
        print(
            f"  {row['workers']:>2} {row['analyses']:>3} {row['failures']:>2} {row['throughput_per_min']:>8} {row['p50_ms']:>8} {row['p95_ms']:>8}"
        )
    shutil.rmtree(workspace, ignore_errors=True)
    if args.json:
        args.json.write_text(json.dumps(report, indent=2) + "\n")


if __name__ == "__main__":
    main()
