import os

import pytest

from app.config import Settings
from tests.support import make_settings


@pytest.fixture
def workspace_root(tmp_path) -> str:  # type: ignore[no-untyped-def]
    root = os.path.join(str(tmp_path), "workspaces")
    os.mkdir(root, 0o700)
    return root


@pytest.fixture
def settings(workspace_root: str) -> Settings:
    return make_settings(workspace_root)
