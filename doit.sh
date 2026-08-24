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
