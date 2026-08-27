#!/usr/bin/env sh
set -eu
python3 -m unittest discover -s tests -v
if command -v php >/dev/null 2>&1; then
  php -l main.php
  php tests/test_dualdb.php
  php -f main.php help >/dev/null
  php -f main.php setup-demo >/dev/null
  php -f main.php status >/dev/null
else
  echo "PHP runtime unavailable; Python contract tests completed" >&2
fi
if [ -n "${ANDROID_HOME:-}" ] && [ -f "${ANDROID_HOME}/platforms/android-35/android.jar" ]; then
  ./android/build-apk.sh
fi
echo "DualDB-Admin verification complete"

# Desktop control-panel dependency. Kept in the project venv.
GUI_ROOT="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
GUI_VENV="$GUI_ROOT/.venv"
command -v python3 >/dev/null 2>&1 || { echo "python3 is required" >&2; exit 1; }
[ -x "$GUI_VENV/bin/python" ] || python3 -m venv "$GUI_VENV"
"$GUI_VENV/bin/python" -m pip install --disable-pip-version-check --upgrade PySide6
touch "$GUI_VENV/.repo-gui-ready"
