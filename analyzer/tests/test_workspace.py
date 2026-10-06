import os

import pytest

from app.workspace.workspace import active_runs, run_workspace, sweep_stale


def test_each_run_gets_a_private_random_directory(workspace_root: str) -> None:
    with run_workspace(workspace_root) as first, run_workspace(workspace_root) as second:
        assert first != second
        assert os.path.dirname(first) == workspace_root
        assert oct(os.stat(first).st_mode & 0o777) == "0o700"
        assert len(active_runs(workspace_root)) == 2
    assert active_runs(workspace_root) == []


def test_the_workspace_is_removed_on_failure(workspace_root: str) -> None:
    with pytest.raises(RuntimeError), run_workspace(workspace_root) as path:
        with open(os.path.join(path, "partial.zip"), "wb") as handle:
            handle.write(b"partial")
        raise RuntimeError("boom")
    assert active_runs(workspace_root) == []


def test_stale_workspaces_are_swept_but_nothing_else(workspace_root: str) -> None:
    os.mkdir(os.path.join(workspace_root, "run-" + "a" * 32))
    os.mkdir(os.path.join(workspace_root, "keep-me"))

    assert sweep_stale(workspace_root) == 1
    assert sorted(os.listdir(workspace_root)) == ["keep-me"]
