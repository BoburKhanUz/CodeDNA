"""The runtime isolation contract (Phase 25): production fails closed.

Pure unit tests: they run anywhere (no sandbox needed).
"""

from __future__ import annotations

import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest import mock

from evaluator import config, isolation, service

GVISOR = "Linux version 4.4.0 #1 SMP Sun Jan 10 15:06:54 PST 2016\n"
HOST = "Linux version 6.8.0-45-generic (buildd@lcy02-amd64-075) (gcc 13.2.0) #45-Ubuntu SMP PREEMPT_DYNAMIC\n"


def proc_version(text: str) -> Path:
    handle = tempfile.NamedTemporaryFile("w", delete=False, suffix=".version")  # noqa: SIM115
    handle.write(text)
    handle.close()
    return Path(handle.name)


class DetectTest(unittest.TestCase):
    def test_gvisor_is_recognized_by_its_synthetic_kernel_version(self) -> None:
        self.assertEqual("gvisor", isolation.detect(proc_version(GVISOR)))

    def test_a_host_kernel_is_container_isolation(self) -> None:
        self.assertEqual("container", isolation.detect(proc_version(HOST)))

    def test_an_unreadable_version_is_never_taken_as_gvisor(self) -> None:
        self.assertEqual("container", isolation.detect(Path("/nonexistent/version")))

    def test_the_fingerprint_must_start_the_version_string(self) -> None:
        self.assertEqual("container", isolation.detect(proc_version("x " + GVISOR)))


class AttestTest(unittest.TestCase):
    def test_production_refuses_container_isolation_even_on_gvisor(self) -> None:
        with self.assertRaisesRegex(isolation.IsolationError, "production requires"):
            isolation.attest("container", True, proc_version(GVISOR))

    def test_production_refuses_gvisor_configuration_without_gvisor_runtime(self) -> None:
        with self.assertRaisesRegex(isolation.IsolationError, "runtime provides container"):
            isolation.attest("gvisor", True, proc_version(HOST))

    def test_production_accepts_an_attested_gvisor_runtime(self) -> None:
        self.assertEqual(isolation.Attestation("gvisor", True), isolation.attest("gvisor", True, proc_version(GVISOR)))

    def test_development_reports_what_it_actually_runs_under(self) -> None:
        self.assertEqual("container", isolation.attest("container", False, proc_version(HOST)).level)
        self.assertEqual("gvisor", isolation.attest("container", False, proc_version(GVISOR)).level)

    def test_an_unknown_level_is_refused(self) -> None:
        with self.assertRaises(isolation.IsolationError):
            isolation.attest("none", False, proc_version(GVISOR))


class ConfigTest(unittest.TestCase):
    def load(self, **env: str) -> config.Config:
        with mock.patch.dict(os.environ, env, clear=False):
            for name in ("EVALUATOR_ISOLATION", "EVALUATOR_PRODUCTION"):
                if name not in env:
                    os.environ.pop(name, None)
            return config.load()

    def test_development_defaults_to_container_isolation(self) -> None:
        loaded = self.load()
        self.assertEqual(("container", False), (loaded.isolation, loaded.production))

    def test_production_with_container_isolation_is_a_configuration_error(self) -> None:
        with self.assertRaisesRegex(config.ConfigError, "requires EVALUATOR_ISOLATION=gvisor"):
            self.load(EVALUATOR_PRODUCTION="true")
        with self.assertRaisesRegex(config.ConfigError, "requires EVALUATOR_ISOLATION=gvisor"):
            self.load(EVALUATOR_PRODUCTION="true", EVALUATOR_ISOLATION="container")

    def test_values_are_strict(self) -> None:
        for env in (
            {"EVALUATOR_ISOLATION": "none"},
            {"EVALUATOR_ISOLATION": "GVISOR"},
            {"EVALUATOR_PRODUCTION": "1"},
            {"EVALUATOR_PRODUCTION": "yes"},
        ):
            with self.subTest(env=env), self.assertRaises(config.ConfigError):
                self.load(**env)

    def test_production_gvisor_loads(self) -> None:
        loaded = self.load(EVALUATOR_PRODUCTION="true", EVALUATOR_ISOLATION="gvisor")
        self.assertEqual(("gvisor", True), (loaded.isolation, loaded.production))


class ServeTest(unittest.TestCase):
    def test_serve_refuses_before_writing_any_heartbeat_and_removes_a_stale_one(self) -> None:
        spool = Path(tempfile.mkdtemp())
        (spool / "heartbeat").write_text(json.dumps({"at": 9999999999, "isolation": "gvisor"}), encoding="utf-8")
        conf = config.Config(spool=spool, slots=(), limits=config.load().limits, poll_seconds=0.2, isolation="gvisor", production=True)
        with mock.patch.object(isolation, "detect", return_value="container"), self.assertRaises(isolation.IsolationError):
            service.serve(conf)
        self.assertFalse((spool / "heartbeat").exists())
        self.assertFalse((spool / "requests").exists(), "nothing is prepared, nothing is claimed")

    def test_main_exits_non_zero_without_a_heartbeat(self) -> None:
        spool = Path(tempfile.mkdtemp())
        env = {"EVALUATOR_SPOOL": str(spool), "EVALUATOR_PRODUCTION": "true", "EVALUATOR_ISOLATION": "gvisor"}
        with (
            mock.patch.dict(os.environ, env),
            mock.patch.object(isolation, "detect", return_value="container"),
            self.assertRaises(SystemExit) as exited,
        ):
            service.main()
        self.assertEqual(2, exited.exception.code)
        self.assertEqual([], list(spool.iterdir()))

    def test_the_heartbeat_publishes_the_attested_isolation(self) -> None:
        spool = Path(tempfile.mkdtemp())
        service.heartbeat(spool, isolation.Attestation("gvisor", True))
        beat = json.loads((spool / "heartbeat").read_text(encoding="utf-8"))
        self.assertEqual(("gvisor", True), (beat["isolation"], beat["production"]))
        self.assertIsInstance(beat["at"], int)


if __name__ == "__main__":
    unittest.main()
