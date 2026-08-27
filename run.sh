#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PY="$ROOT/.venv/bin/python"
if [[ ! -f "$ROOT/.venv/.repo-gui-ready" ]] || [[ ! -x "$PY" ]] || ! "$PY" -c 'import PySide6' >/dev/null 2>&1; then
    "$ROOT/install.sh"
fi
exec env PROJECT_TITLE="DualDB-Admin" "$PY" "$ROOT/project_gui.py" "$@"
