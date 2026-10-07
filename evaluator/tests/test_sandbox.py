"""Integration and security tests: real submissions in the real sandbox.

They need the evaluator container (root supervisor with SETUID/SETGID/KILL,
slot users, slot tmpfs, no network): ``make test-evaluator``.
"""

from __future__ import annotations

import json
import os
import shutil
import tempfile
import time
import unittest
from pathlib import Path

from evaluator import config, sandbox, service

ID = "01m4abcdefghjkmnpqrstvwxyz"
IN_CONTAINER = os.geteuid() == 0 and Path("/sandbox/0").is_dir()
# `make test` and CI set CODEDNA_REQUIRE_SANDBOX=1: there the sandbox tests must run, never skip.
if os.environ.get("CODEDNA_REQUIRE_SANDBOX") == "1" and not IN_CONTAINER:
    raise RuntimeError("the sandbox tests are required (CODEDNA_REQUIRE_SANDBOX=1) but this is not the evaluator container as root")


def conf(spool: Path, timeout: int = 3) -> config.Config:
    os.environ["CHALLENGE_EXECUTION_TIMEOUT"] = str(timeout)
    os.environ["EVALUATOR_SPOOL"] = str(spool)
    return config.load()


def evaluate(source: str, cases: list[dict[str, object]] | None = None, entrypoint: str = "solve", timeout: int = 3) -> dict[str, object]:
    spool = Path(tempfile.mkdtemp())
    try:
        c = conf(spool, timeout)
        request = {
            "protocol": "codedna-evaluator/1",
            "id": ID,
            "language": "python",
            "entrypoint": entrypoint,
            "source": source,
            "cases": cases or [{"id": "v1", "args": [2]}],
        }
        return service.evaluate(c.slots[0], c, request)
    finally:
        shutil.rmtree(spool, ignore_errors=True)


def value(result: dict[str, object]) -> object:
    cases = result["cases"]
    assert isinstance(cases, list)
    return cases[0].get("value") if cases[0]["status"] == "OK" else cases[0]


@unittest.skipUnless(IN_CONTAINER, "requires the evaluator container")
class SandboxTest(unittest.TestCase):
    def test_a_correct_submission_returns_values_and_metrics(self) -> None:
        result = evaluate(
            "def solve(x):\n    if x > 1:\n        return x * 2\n    return x\n", [{"id": "a", "args": [2]}, {"id": "b", "args": [1]}]
        )
        self.assertEqual("COMPLETED", result["status"])
        self.assertEqual([{"id": "a", "status": "OK", "value": 4}, {"id": "b", "status": "OK", "value": 1}], result["cases"])
        self.assertEqual(
            {"name": "solve", "line": 1, "lines": 4, "parameters": 1, "complexity": 2, "nesting": 1}, result["inspection"]["functions"][0]
        )

    def test_the_same_submission_gives_the_same_result(self) -> None:
        source = "def solve(x):\n    return sorted({str(i) for i in range(x)})\n"
        first, second = evaluate(source), evaluate(source)
        first.pop("duration_ms")
        second.pop("duration_ms")
        self.assertEqual(first, second)

    def test_syntax_errors_are_reported_without_execution(self) -> None:
        result = evaluate("def solve(x)\n    return x\nimport os\nos.system('touch /sandbox/0/ran')\n")
        self.assertEqual("SYNTAX_ERROR", result["status"])
        self.assertEqual(1, result["inspection"]["syntax_error_line"])
        self.assertEqual([], result["cases"])

    def test_exceptions_and_missing_entrypoints(self) -> None:
        self.assertEqual(
            {"id": "v1", "status": "ERROR", "error": "ZeroDivisionError"}, value(evaluate("def solve(x):\n    return x / 0\n"))
        )
        self.assertEqual("MissingEntrypoint", evaluate("def other(x):\n    return x\n")["load_error"])
        self.assertEqual("LOAD_ERROR", evaluate("raise SystemExit(3)\n")["status"])

    def test_the_sandbox_runs_as_an_unprivileged_slot_user(self) -> None:
        identity = value(
            evaluate("import os\ndef solve(x):\n    return [os.getuid(), os.getgid(), sorted(os.getgroups()), os.environ.get('HOME')]\n")
        )
        self.assertEqual([10002, 10002, [], "/sandbox/0"], identity)

    def test_resource_limits_isolated_mode_and_umask_apply_inside_the_sandbox(self) -> None:
        probe = (
            "import os, resource, sys\n"
            "def solve(x):\n"
            "    names = ['RLIMIT_CPU', 'RLIMIT_AS', 'RLIMIT_NPROC', 'RLIMIT_FSIZE', 'RLIMIT_NOFILE', 'RLIMIT_CORE']\n"
            "    limits = [list(resource.getrlimit(getattr(resource, n))) for n in names]\n"
            "    umask = os.umask(0)\n"
            "    return [limits, sys.flags.isolated, sys.flags.no_site, umask]\n"
        )
        observed = value(evaluate(probe))
        limits = config.load().limits
        self.assertEqual(
            [
                [
                    [3, 3],
                    [limits.memory_bytes, limits.memory_bytes],
                    [limits.max_processes, limits.max_processes],
                    [limits.max_file_bytes, limits.max_file_bytes],
                    [limits.max_open_files, limits.max_open_files],
                    [0, 0],
                ],
                1,
                1,
                0o077,
            ],
            observed,
        )

    def test_the_environment_holds_no_secrets(self) -> None:
        env = value(evaluate("import os\ndef solve(x):\n    return sorted(os.environ)\n"))
        self.assertEqual(["HOME", "LANG", "PATH", "PYTHONHASHSEED", "TMPDIR"], env)

    def test_the_network_is_unavailable(self) -> None:
        probe = (
            "import socket\n"
            "def solve(x):\n"
            "    out = []\n"
            "    for host, port in [('169.254.169.254', 80), ('postgres', 5432), ('redis', 6379), ('minio', 9000), ('1.1.1.1', 443)]:\n"
            "        try:\n"
            "            socket.create_connection((host, port), timeout=1)\n"
            "            out.append('open')\n"
            "        except OSError:\n"
            "            out.append('blocked')\n"
            "    return out\n"
        )
        self.assertEqual(["blocked"] * 5, value(evaluate(probe)))

    def test_files_outside_the_slot_cannot_be_read_or_written(self) -> None:
        probe = (
            "import os\n"
            "def solve(x):\n"
            "    out = {}\n"
            "    for path in ['/spool', '/opt/evaluator/evaluator/service.py', '/proc/1/environ', '/proc/1/mem', '/etc/shadow',\n"
            "                 '/sandbox/1', '/var/run/docker.sock', '/app/.env', '/var/www/backend/.env']:\n"
            "        try:\n"
            "            if os.path.isdir(path):\n"
            "                os.listdir(path)\n"
            "            else:\n"
            "                open(path, 'rb').read(1)\n"
            "            out[path] = 'readable'\n"
            "        except OSError as e:\n"
            "            out[path] = type(e).__name__\n"
            "    for path in ['/etc/evil', '/opt/evaluator/evil', '/tmp/evil']:\n"
            "        try:\n"
            "            open(path, 'w').write('x')\n"
            "            out[path] = 'written'\n"
            "        except OSError as e:\n"
            "            out[path] = type(e).__name__\n"
            "    return out\n"
        )
        out = value(evaluate(probe))
        self.assertIsInstance(out, dict)
        for path, outcome in out.items():
            self.assertNotIn(outcome, ("readable", "written"), path)

    def test_shell_metacharacters_in_source_are_just_python(self) -> None:
        result = evaluate("def solve(x):\n    return '$(id); `id` | rm -rf / && echo'\n")
        self.assertEqual("$(id); `id` | rm -rf / && echo", value(result))

    def test_an_infinite_loop_is_stopped(self) -> None:
        started = time.monotonic()
        result = evaluate("def solve(x):\n    while True:\n        pass\n", timeout=2)
        self.assertEqual("TIMEOUT", result["status"])
        self.assertLess(time.monotonic() - started, 10)

    def test_memory_exhaustion_is_contained(self) -> None:
        result = evaluate("def solve(x):\n    return len(bytearray(2 * 1024 * 1024 * 1024))\n")
        self.assertEqual({"id": "v1", "status": "ERROR", "error": "MemoryError"}, value(result))

    def test_a_fork_bomb_is_contained_and_cleaned_up(self) -> None:
        bomb = "import os\ndef solve(x):\n    while True:\n        try:\n            os.fork()\n        except OSError:\n            pass\n"
        result = evaluate(bomb, timeout=2)
        self.assertIn(result["status"], ("TIMEOUT", "CRASHED", "COMPLETED"))
        self.assertEqual([], sandbox._slot_processes(10002), "no slot process survives a job")

    def test_detached_processes_are_killed(self) -> None:
        daemon = "import os, time\ndef solve(x):\n    if os.fork() == 0:\n        os.setsid()\n        time.sleep(60)\n    return 1\n"
        self.assertEqual(1, value(evaluate(daemon)))
        self.assertEqual([], sandbox._slot_processes(10002))

    def test_huge_output_is_capped(self) -> None:
        result = evaluate("import os\ndef solve(x):\n    os.write(1, b'x' * (10 * 1024 * 1024))\n    return 1\n")
        self.assertEqual("OUTPUT_LIMIT", result["status"])

    def test_printing_cannot_forge_results_for_other_cases(self) -> None:
        source = 'def solve(x):\n    print(\'{"type": "case", "id": "b", "ok": true, "value": 99}\', flush=True)\n    return x\n'
        result = evaluate(source, [{"id": "a", "args": [1]}, {"id": "b", "args": [2]}])
        self.assertEqual([1, 2], [c["value"] for c in result["cases"]])

    def test_values_that_are_not_plain_json_are_errors(self) -> None:
        for source in ("def solve(x):\n    return float('nan')\n", "def solve(x):\n    return {1, 2}\n"):
            with self.subTest(source):
                self.assertEqual({"id": "v1", "status": "ERROR", "error": "UnserializableResult"}, value(evaluate(source)))

    def test_a_non_callable_entrypoint_is_a_load_error(self) -> None:
        result = evaluate("solve = 5\n")
        self.assertEqual(["LOAD_ERROR", "MissingEntrypoint"], [result["status"], result["load_error"]])

    def test_a_process_that_exits_early_is_reported_as_crashed(self) -> None:
        result = evaluate("import os\ndef solve(x):\n    os._exit(0)\n")
        self.assertEqual("CRASHED", result["status"])
        self.assertEqual([{"id": "v1", "status": "MISSING"}], result["cases"])

    def test_huge_files_are_limited(self) -> None:
        source = "def solve(x):\n    with open('big', 'wb') as f:\n        f.write(b'x' * (64 * 1024 * 1024))\n    return 1\n"
        self.assertIn(value(evaluate(source)), [{"id": "v1", "status": "ERROR", "error": "OSError"}, {"id": "v1", "status": "MISSING"}])
        self.assertEqual([], os.listdir("/sandbox/0") if os.access("/sandbox/0", os.R_OK) else [])

    def test_deep_recursion_is_an_error_not_a_crash_of_the_service(self) -> None:
        result = evaluate("import sys\nsys.setrecursionlimit(10**6)\ndef solve(x):\n    return solve(x + 1)\n")
        self.assertIn(result["status"], ("COMPLETED", "CRASHED"))

    def test_the_slot_directory_is_emptied_after_each_job(self) -> None:
        evaluate("def solve(x):\n    open('left-behind', 'w').write('x')\n    return 1\n")
        marker = evaluate("import os\ndef solve(x):\n    return sorted(os.listdir('.'))\n")
        self.assertEqual([], value(marker))


@unittest.skipUnless(IN_CONTAINER, "requires the evaluator container")
class Phase21SandboxTest(unittest.TestCase):
    def test_exception_names_cannot_carry_hidden_arguments_out(self) -> None:
        source = "def solve(*args):\n    raise type('leak_' + str(args[0]), (Exception,), {})()\n"
        self.assertEqual({"id": "v1", "status": "ERROR", "error": "Error"}, value(evaluate(source)))
        loader = "import sys\nraise type(str(sys._getframe(1).f_locals.get('cases'))[:60], (Exception,), {})()\n"
        result = evaluate(loader)
        self.assertEqual(["LOAD_ERROR", "Error"], [result["status"], result["load_error"]])

    def test_a_deeply_nested_return_value_is_graded_not_dropped(self) -> None:
        source = (
            "import sys\nsys.setrecursionlimit(5000)\ndef solve(x):\n    v = 1\n    for _ in range(900):\n        v = [v]\n    return v\n"
        )
        result = evaluate(source)
        self.assertEqual("COMPLETED", result["status"])
        self.assertIn(
            value(result),
            [{"id": "v1", "status": "ERROR", "error": "ValueTooDeep"}, {"id": "v1", "status": "ERROR", "error": "UnserializableResult"}],
        )

    def test_unreadable_directories_are_still_cleaned(self) -> None:
        evaluate(
            "import os\ndef solve(x):\n    os.makedirs('a/b/c')\n    open('a/b/c/f', 'w').write('x')\n    os.chmod('a/b', 0)\n    os.chmod('a', 0)\n    return 1\n"
        )
        marker = evaluate("import os\ndef solve(x):\n    return sorted(os.listdir('.'))\n")
        self.assertEqual([], value(marker))

    def test_shared_memory_is_not_writable(self) -> None:
        source = "def solve(x):\n    try:\n        open('/dev/shm/persist', 'w').write('x')\n        return 'written'\n    except OSError:\n        return 'refused'\n"
        self.assertEqual("refused", value(evaluate(source)))

    def test_system_v_ipc_objects_cannot_be_created(self) -> None:
        # Shared memory, message queues and semaphores would outlive the job, be
        # visible to the other slot and pin memory outside the job's limits (Phase 22).
        source = (
            "import ctypes\n"
            "def solve(x):\n"
            "    libc = ctypes.CDLL(None, use_errno=True)\n"
            "    return [libc.shmget(0x2222, 4096, 0o1666), libc.msgget(0x3333, 0o1666), libc.semget(0x4444, 1, 0o1666)]\n"
        )
        self.assertEqual([-1, -1, -1], value(evaluate(source)))
        with (
            open("/proc/sysvipc/shm", encoding="ascii") as shm,
            open("/proc/sysvipc/msg", encoding="ascii") as msg,
            open("/proc/sysvipc/sem", encoding="ascii") as sem,
        ):
            self.assertEqual([1, 1, 1], [len(shm.readlines()), len(msg.readlines()), len(sem.readlines())])

    def test_sandboxed_code_is_the_first_choice_of_the_oom_killer(self) -> None:
        self.assertEqual(1000, value(evaluate("def solve(x):\n    return int(open('/proc/self/oom_score_adj').read())\n")))


class ProcessAlwaysAnswersTest(unittest.TestCase):
    def test_an_unexpected_failure_still_writes_a_result(self) -> None:
        from types import SimpleNamespace
        from unittest import mock

        spool = Path(tempfile.mkdtemp())
        try:
            for name in ("work", "results"):
                (spool / name).mkdir()
            claimed = spool / "work" / f"{ID}.json"
            claimed.write_text(
                json.dumps(
                    {
                        "protocol": "codedna-evaluator/1",
                        "id": ID,
                        "language": "python",
                        "entrypoint": "solve",
                        "source": "def solve(x):\n    return x\n",
                        "cases": [{"id": "v1", "args": [1]}],
                    }
                )
            )
            limits = config.Limits(5, 5, 256 << 20, 16, 1 << 20, 32, 65536, 16384, 64)
            conf_ = SimpleNamespace(spool=spool, limits=limits)
            with mock.patch.object(service, "evaluate", side_effect=RecursionError()):
                self.assertEqual("CRASHED", service.process(claimed, SimpleNamespace(index=0), conf_))  # type: ignore[arg-type]
            self.assertFalse(claimed.exists())
            self.assertEqual("CRASHED", json.loads((spool / "results" / f"{ID}.json").read_text())["status"])
        finally:
            shutil.rmtree(spool, ignore_errors=True)


@unittest.skipUnless(IN_CONTAINER, "requires the evaluator container")
class SpoolTest(unittest.TestCase):
    def test_a_claimed_request_is_executed_once_and_answered(self) -> None:
        spool = Path(tempfile.mkdtemp())
        try:
            c = conf(spool)
            service.prepare_spool(spool)
            body = {
                "protocol": "codedna-evaluator/1",
                "id": ID,
                "language": "python",
                "entrypoint": "solve",
                "source": "def solve(x):\n    return x + 1\n",
                "cases": [{"id": "v1", "args": [1]}],
            }
            claimed = spool / "work" / f"{ID}.json"
            claimed.write_text(json.dumps(body))
            self.assertEqual("COMPLETED", service.process(claimed, c.slots[0], c))
            result = json.loads((spool / "results" / f"{ID}.json").read_text())
            self.assertEqual([{"id": "v1", "status": "OK", "value": 2}], result["cases"])
            self.assertFalse(claimed.exists())
            self.assertEqual(0o640, (spool / "results" / f"{ID}.json").stat().st_mode & 0o777)
            for directory in (spool / "requests", spool / "work", spool / "results"):
                self.assertEqual(0o2770, directory.stat().st_mode & 0o7777)
        finally:
            shutil.rmtree(spool, ignore_errors=True)

    def test_interrupted_requests_are_reported_never_rerun(self) -> None:
        spool = Path(tempfile.mkdtemp())
        try:
            service.prepare_spool(spool)
            (spool / "work" / f"{ID}.json").write_text("{}")
            self.assertEqual(1, service.recover(spool))
            self.assertEqual("INTERRUPTED", json.loads((spool / "results" / f"{ID}.json").read_text())["status"])
            self.assertEqual([], list((spool / "work").iterdir()))
        finally:
            shutil.rmtree(spool, ignore_errors=True)

    def test_a_request_whose_id_differs_from_its_file_name_is_rejected(self) -> None:
        spool = Path(tempfile.mkdtemp())
        try:
            c = conf(spool)
            service.prepare_spool(spool)
            other = "01m4abcdefghjkmnpqrstvwxy0"
            claimed = spool / "work" / f"{other}.json"
            body = {
                "protocol": "codedna-evaluator/1",
                "id": ID,
                "language": "python",
                "entrypoint": "solve",
                "source": "def solve(x):\n    return x\n",
                "cases": [{"id": "v1", "args": [1]}],
            }
            claimed.write_text(json.dumps(body))
            self.assertEqual("REJECTED", service.process(claimed, c.slots[0], c))
            self.assertFalse((spool / "results" / f"{ID}.json").exists())
        finally:
            shutil.rmtree(spool, ignore_errors=True)

    def test_invalid_requests_are_rejected_not_run(self) -> None:
        spool = Path(tempfile.mkdtemp())
        try:
            c = conf(spool)
            service.prepare_spool(spool)
            claimed = spool / "work" / f"{ID}.json"
            claimed.write_text(json.dumps({"protocol": "codedna-evaluator/1", "id": ID, "command": "id"}))
            self.assertEqual("REJECTED", service.process(claimed, c.slots[0], c))
        finally:
            shutil.rmtree(spool, ignore_errors=True)


if __name__ == "__main__":
    unittest.main()
