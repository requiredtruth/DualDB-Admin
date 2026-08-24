#!/usr/bin/env sh
set -eu
root_dir=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
output_dir="${root_dir}/../build"
mkdir -p "$output_dir"

if [ -x "${root_dir}/gradlew" ]; then
  (cd "$root_dir" && ./gradlew --no-daemon assembleDebug)
  cp "${root_dir}/app/build/outputs/apk/debug/app-debug.apk" "${output_dir}/DualDBAdmin.apk"
elif command -v gradle >/dev/null 2>&1; then
  (cd "$root_dir" && gradle --no-daemon assembleDebug)
  cp "${root_dir}/app/build/outputs/apk/debug/app-debug.apk" "${output_dir}/DualDBAdmin.apk"
else
  sdk="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-}}"
  [ -n "$sdk" ] || { echo "Android SDK or Gradle is required" >&2; exit 2; }
  platform="${sdk}/platforms/android-35/android.jar"
  [ -f "$platform" ] || { echo "Android platform 35 is required" >&2; exit 2; }
  tools=$(find "${sdk}/build-tools" -mindepth 1 -maxdepth 1 -type d | sort -V | tail -1)
  [ -x "${tools}/aapt" ] && [ -x "${tools}/d8" ] && [ -x "${tools}/apksigner" ] || { echo "aapt, d8, and apksigner are required" >&2; exit 2; }
  work=$(mktemp -d)
  trap 'rm -rf "$work"' EXIT INT TERM
  mkdir -p "$work/gen" "$work/classes" "$work/dex"
  "${tools}/aapt" package -f -m -J "$work/gen" -M "${root_dir}/app/src/main/AndroidManifest.xml" -S "${root_dir}/app/src/main/res" -I "$platform"
  javac -source 8 -target 8 -bootclasspath "$platform" -d "$work/classes" "${root_dir}/app/src/main/java/org/dualdb/admin/MainActivity.java" "$work/gen/org/dualdb/admin/R.java"
  find "$work/classes" -name '*.class' -print0 | xargs -0 "${tools}/d8" --lib "$platform" --output "$work/dex"
  "${tools}/aapt" package -f -M "${root_dir}/app/src/main/AndroidManifest.xml" -S "${root_dir}/app/src/main/res" -I "$platform" -F "$work/unsigned.apk"
  (cd "$work/dex" && "${tools}/aapt" add "$work/unsigned.apk" classes.dex >/dev/null)
  DUALDB_TEMP_KS_PASS=$(python3 -c 'import secrets; print(secrets.token_urlsafe(24))')
  export DUALDB_TEMP_KS_PASS
  keytool -genkeypair -keystore "$work/debug.keystore" -storepass "$DUALDB_TEMP_KS_PASS" -keypass "$DUALDB_TEMP_KS_PASS" -alias temporary -keyalg RSA -keysize 2048 -validity 30 -dname "CN=Temporary Debug Build" >/dev/null 2>&1
  "${tools}/apksigner" sign --ks "$work/debug.keystore" --ks-key-alias temporary --ks-pass env:DUALDB_TEMP_KS_PASS --key-pass env:DUALDB_TEMP_KS_PASS --out "${output_dir}/DualDBAdmin.apk" "$work/unsigned.apk"
fi

echo "APK: ${output_dir}/DualDBAdmin.apk"
