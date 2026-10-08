#!/usr/bin/env python3
"""HTTP load test (Phase 26, docs/performance/load-testing.md).

Drives the running stack through Nginx with authenticated sessions made by
`php artisan benchmark:sessions` (benchmark database only), and reports per
scenario: concurrency, requests, throughput, P50/P95/P99 latency, errors,
plus PostgreSQL connections and the analysis queue length sampled during the
run. Run it with `make loadtest`, which prepares sessions, points the
development backend and worker at the benchmark database and restores them.

Scenarios:
  A  authenticated reads        me, profile, billing, project list
  B  project / DNA / history    project, DNA, history (cursor), growth, competencies, skill gaps, roadmaps
  C  organization / team        organizations, organization, members, projects, audit (cursor, admins), analytics
  D  analysis submissions       writers start analyses of fresh snapshots (real jobs) and repeat them (idempotent)
  E  mixed                      70% A+B, 20% C, 10% D
  F  concurrent writes          writers create projects concurrently: the active-project quota must hold

Standard library only.
"""

from __future__ import annotations

import argparse
import http.client
import json
import random
import statistics
import subprocess
import threading
import time
from collections import defaultdict
from pathlib import Path
from typing import Any

HOST = "localhost"


class Client:
    """One keep-alive connection per thread."""

    def __init__(self, host: str, port: int) -> None:
        self.host, self.port = host, port
        self.conn = http.client.HTTPConnection(host, port, timeout=30)

    def request(self, method: str, path: str, session: dict[str, Any], body: dict[str, Any] | None = None) -> tuple[int, float, bytes]:
        headers = {
            "Accept": "application/json",
            "Cookie": session["cookie"],
            # A first-party browser request (Sanctum stateful domain, RequireSession).
            "Origin": f"http://{HOST}",
            "Referer": f"http://{HOST}/app",
        }
        data = None
        if method != "GET":
            headers["X-XSRF-TOKEN"] = session["xsrf"]
            headers["Content-Type"] = "application/json"
            data = json.dumps(body or {}).encode()
        for attempt in (1, 2):
            started = time.perf_counter()
            try:
                self.conn.request(method, path, body=data, headers=headers)
                response = self.conn.getresponse()
                payload = response.read()
                return response.status, (time.perf_counter() - started) * 1000, payload
            except (http.client.HTTPException, OSError):
                self.conn.close()
                self.conn = http.client.HTTPConnection(self.host, self.port, timeout=30)
                if attempt == 2:
                    return 599, (time.perf_counter() - started) * 1000, b""
        return 599, 0.0, b""


class Recorder:
    def __init__(self) -> None:
        self.lock = threading.Lock()
        self.samples: dict[str, list[float]] = defaultdict(list)
        self.statuses: dict[str, dict[int, int]] = defaultdict(lambda: defaultdict(int))

    def add(self, group: str, status: int, ms: float) -> None:
        with self.lock:
            self.samples[group].append(ms)
            self.statuses[group][status] += 1


class Bucket:
    """Paces analysis submissions below the per-user limit (analysis-create:
    10 per minute): at most 8 per writer per minute in total. A test of how
    the system handles submissions, not of the rate limiter."""

    def __init__(self, per_second: float) -> None:
        self.per_second = per_second
        self.tokens = 1.0
        self.updated = time.monotonic()
        self.lock = threading.Lock()

    def take(self) -> bool:
        with self.lock:
            now = time.monotonic()
            self.tokens = min(2.0, self.tokens + (now - self.updated) * self.per_second)
            self.updated = now
            if self.tokens >= 1.0:
                self.tokens -= 1.0
                return True
            return False


class Monitor(threading.Thread):
    """Samples PostgreSQL connections and the analysis queue once a second."""

    def __init__(self, compose: list[str]) -> None:
        super().__init__(daemon=True)
        self.compose = compose
        self.stop = threading.Event()
        self.connections: list[tuple[int, int]] = []
        self.queue: list[int] = []

    def sh(self, *args: str) -> str:
        return subprocess.run([*self.compose, "exec", "-T", *args], capture_output=True, text=True, check=False).stdout.strip()

    def run(self) -> None:
        while not self.stop.is_set():
            row = self.sh(
                "postgres",
                "psql",
                "-U",
                "codedna",
                "-d",
                "codedna",
                "-At",
                "-c",
                "SELECT count(*), count(*) FILTER (WHERE state = 'active') FROM pg_stat_activity WHERE datname = 'codedna_benchmark'",
            )
            if "|" in row:
                total, active = row.split("|")
                self.connections.append((int(total), int(active)))
            keys = self.sh("redis", "redis-cli", "-n", "5", "--scan", "--pattern", "*queues:analysis")
            length = 0
            for key in keys.splitlines():
                value = self.sh("redis", "redis-cli", "-n", "5", "llen", key)
                length += int(value) if value.isdigit() else 0
            self.queue.append(length)
            self.stop.wait(1.0)


def redis_audit(compose: list[str]) -> dict[str, Any]:
    """Key families in the load test's Redis databases (5: sessions, rate
    limits, queues; 6: cache): how many, how many never expire, and sampled
    value sizes. Run after the scenarios, before the data is removed."""
    import re

    def sh(*args: str) -> str:
        return subprocess.run([*compose, "exec", "-T", "redis", "redis-cli", *args], capture_output=True, text=True, check=False).stdout

    audit: dict[str, Any] = {}
    for db in ("5", "6"):
        families: dict[str, list[str]] = defaultdict(list)
        for key in sh("-n", db, "--scan", "--count", "1000").split():
            family = re.sub(r"[0-9A-Za-z]{20,}", "<id>", key)
            family = re.sub(r"\d+", "<n>", family)
            families[family].append(key)
        rows = {}
        for family, keys in sorted(families.items(), key=lambda kv: -len(kv[1])):
            sample = keys[:20]
            ttls = [int(sh("-n", db, "ttl", k).strip() or -2) for k in sample]
            sizes = [int(sh("-n", db, "memory", "usage", k).strip() or 0) for k in sample]
            rows[family] = {
                "keys": len(keys),
                "no_expiry_in_sample": sum(1 for t in ttls if t == -1),
                "max_ttl_s": max(ttls) if ttls else None,
                "avg_bytes": round(sum(sizes) / len(sizes)) if sizes else 0,
            }
        audit[db] = rows
    return audit


def pick(values: list[float], p: float) -> float:
    ordered = sorted(values)
    return round(ordered[min(len(ordered) - 1, max(0, round(p * len(ordered)) - 1))], 1)


def reads_a(rng: random.Random, s: dict[str, Any]) -> tuple[str, str, str]:
    return "A", "GET", rng.choice(["/api/v1/me", "/api/v1/profile", "/api/v1/billing", "/api/v1/projects"])


def reads_b(rng: random.Random, s: dict[str, Any]) -> tuple[str, str, str]:
    if not s["projects"]:
        return reads_a(rng, s)
    base = f"/api/v1/projects/{rng.choice(s['projects'])}"
    return (
        "B",
        "GET",
        base
        + rng.choice(["", "/dna", "/history?cursor=&per_page=10", "/growth", "/competencies", "/skill-gaps", "/roadmaps", "/analyses"]),
    )


def reads_c(rng: random.Random, s: dict[str, Any]) -> tuple[str, str, str]:
    base = f"/api/v1/organizations/{s['organization']}"
    paths = ["/api/v1/organizations", base, base + "/members", base + "/projects", base + "/analytics"]
    if s["admin"]:
        paths += [base + "/audit-events?cursor=", base + "/billing"]
    return "C", "GET", rng.choice(paths)


def run_scenario(name: str, args: argparse.Namespace, sessions: dict[str, Any], recorder: Recorder) -> dict[str, Any]:
    readers, writers = sessions["readers"], sessions["writers"]
    deadline = time.monotonic() + args.duration
    submitted_lock = threading.Lock()
    quota_results: dict[str, list[int]] = defaultdict(list)
    bucket = Bucket(len(writers) * 8 / 60)

    def submit(rng: random.Random, client: Client) -> None:
        writer = rng.choice(writers)
        snapshot = rng.choice(writer["snapshots"])
        status, ms, _ = client.request(
            "POST",
            f"/api/v1/projects/{writer['project']}/analyses",
            writer,
            {"source_snapshot_id": snapshot, "result_type": "static_analysis"},
        )
        recorder.add("D:new" if status == 202 else "D:repeat", status, ms)

    def create_project(rng: random.Random, client: Client) -> None:
        writer = rng.choice(writers)
        status, ms, _ = client.request(
            "POST", "/api/v1/projects", writer, {"name": "Concurrent", "slug": f"c-{rng.getrandbits(48):x}", "source_type": "UPLOAD"}
        )
        recorder.add("F:create", status, ms)
        with submitted_lock:
            quota_results[writer["project"]].append(status)

    def worker(seed: int) -> None:
        rng = random.Random(seed)
        client = Client(HOST, args.port)
        while time.monotonic() < deadline:
            if name == "F":
                create_project(rng, client)
                continue
            if name == "D" or (name == "E" and rng.random() < 0.10):
                if bucket.take():
                    submit(rng, client)
                    continue
                if name == "D":
                    time.sleep(0.05)
                    continue
            session = rng.choice(readers)
            if name == "A":
                group, method, path = reads_a(rng, session)
            elif name == "B":
                group, method, path = reads_b(rng, session)
            elif name == "C":
                group, method, path = reads_c(rng, session)
            else:
                group, method, path = (reads_c if rng.random() < 0.22 else rng.choice([reads_a, reads_b]))(rng, session)
            status, ms, _ = client.request(method, path, session)
            recorder.add(group, status, ms)

    started = time.monotonic()
    threads = [threading.Thread(target=worker, args=(i,)) for i in range(args.concurrency)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    return {"elapsed": time.monotonic() - started, "quota": quota_results}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("sessions", type=Path)
    parser.add_argument("--scenarios", default="A,B,C,D,E,F")
    parser.add_argument("--concurrency", type=int, default=16)
    parser.add_argument("--duration", type=float, default=30.0)
    parser.add_argument("--port", type=int, default=80)
    parser.add_argument("--json", type=Path)
    parser.add_argument("--compose", default="docker compose")
    args = parser.parse_args()
    sessions = json.loads(args.sessions.read_text())
    report: dict[str, Any] = {"concurrency": args.concurrency, "duration_s": args.duration, "scenarios": {}}

    print(f"{'scenario':10} {'group':10} {'requests':>8} {'req/s':>7} {'p50':>7} {'p95':>7} {'p99':>7}  statuses")
    for name in args.scenarios.split(","):
        recorder = Recorder()
        monitor = Monitor(args.compose.split())
        monitor.start()
        outcome = run_scenario(name, args, sessions, recorder)
        monitor.stop.set()
        monitor.join()
        rows = {}
        for group in sorted(recorder.samples):
            values = recorder.samples[group]
            statuses = dict(sorted(recorder.statuses[group].items()))
            rows[group] = {
                "requests": len(values),
                "throughput_rps": round(len(values) / outcome["elapsed"], 1),
                "p50_ms": pick(values, 0.50),
                "p95_ms": pick(values, 0.95),
                "p99_ms": pick(values, 0.99),
                "mean_ms": round(statistics.fmean(values), 1),
                "statuses": statuses,
            }
            print(
                f"{name:10} {group:10} {len(values):>8} {rows[group]['throughput_rps']:>7} {rows[group]['p50_ms']:>7} {rows[group]['p95_ms']:>7} {rows[group]['p99_ms']:>7}  {statuses}"
            )
        conns = monitor.connections or [(0, 0)]
        db = {"max_connections": max(c[0] for c in conns), "max_active": max(c[1] for c in conns)}
        queue = {"max_length": max(monitor.queue or [0]), "final_length": (monitor.queue or [0])[-1]}
        print(
            f"{'':10} {'db':10} connections max {db['max_connections']} (active max {db['max_active']}); analysis queue max {queue['max_length']}, last {queue['final_length']}"
        )
        entry: dict[str, Any] = {"groups": rows, "db": db, "queue": queue}
        if name == "F":
            created = {project: sum(1 for s in statuses if s == 201) for project, statuses in outcome["quota"].items()}
            entry["created_per_writer_max"] = max(created.values() or [0])
            print(
                f"{'':10} {'quota':10} most projects any writer created concurrently: {entry['created_per_writer_max']} (each had 1 of 3 active)"
            )
        report["scenarios"][name] = entry
    report["redis"] = redis_audit(args.compose.split())
    print("\nRedis key families after the run (db: family keys / no-expiry in sample / max TTL s / avg bytes):")
    for db, rows in report["redis"].items():
        for family, row in list(rows.items())[:12]:
            print(f"  {db}: {family[:70]:70} {row['keys']:>6} / {row['no_expiry_in_sample']:>2} / {row['max_ttl_s']} / {row['avg_bytes']}")
    if args.json:
        args.json.write_text(json.dumps(report, indent=2) + "\n")


if __name__ == "__main__":
    main()
