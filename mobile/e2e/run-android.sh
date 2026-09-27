#!/usr/bin/env bash
# Runs inside the Android emulator step of .github/workflows/android-check.yml:
# installs the app, taps through every main screen in light and then dark
# mode (e2e/flows), and keeps a screenshot of each plus any crash found in
# the phone's log. Everything lands in e2e-output/.
set -u
cd "$(dirname "$0")/.."
ROOT="$PWD"
OUT="$ROOT/e2e-output"
mkdir -p "$OUT/shots"
APP=com.kounselia.app

adb install -r android/app/build/outputs/apk/release/app-release.apk
adb logcat -b all -c

run_flows() {
  local prefix="$1"
  for flow in e2e/flows/*.yaml; do
    name="$(basename "$flow" .yaml)"
    touch "$OUT/.flow-start"
    # A previous flow ending in a crash mustn't stop the rest.
    (cd "$OUT/shots" && "$HOME/.maestro/bin/maestro" test -e "P=$prefix-" "$ROOT/$flow") > "$OUT/$prefix-$name.log" 2>&1
    status=$?
    if [ "$status" -eq 0 ]; then result=passed; else result=FAILED; fi
    running=$(adb shell pidof "$APP" > /dev/null && echo "app running" || echo "APP NOT RUNNING")
    # What the phone shows at the end of this flow, whatever happened.
    adb exec-out screencap -p > "$OUT/shots/$prefix-$name-end.png" 2>/dev/null
    if [ "$status" -ne 0 ]; then
      adb shell uiautomator dump /sdcard/ui.xml > /dev/null 2>&1 && adb pull /sdcard/ui.xml "$OUT/$prefix-$name-screen.xml" > /dev/null 2>&1
    fi
    echo "$prefix $name: $result ($running)" | tee -a "$OUT/summary.txt"
    # Keep any crash, then start the next flow with a clean log.
    adb logcat -d -b crash > "$OUT/$prefix-$name-crash.txt" 2>/dev/null
    adb logcat -d -s ReactNativeJS:V ReactNative:V AndroidRuntime:E > "$OUT/$prefix-$name-js.txt" 2>/dev/null
    [ -s "$OUT/$prefix-$name-crash.txt" ] || rm -f "$OUT/$prefix-$name-crash.txt"
    # Maestro saves screenshots next to the flow files or in its own
    # folder, depending on version: gather this flow's into e2e-output/shots.
    find "$ROOT/e2e/flows" "$HOME/.maestro" "$OUT/shots" -maxdepth 4 -name '*.png' -newer "$OUT/.flow-start" -print0 2>/dev/null |
      while IFS= read -r -d '' shot; do
        base="$(basename "$shot")"
        case "$base" in "$prefix"-*) dest="$base" ;; *) dest="$prefix-${base#\$\{P\}}" ;; esac
        [ "$shot" = "$OUT/shots/$dest" ] || mv "$shot" "$OUT/shots/$dest"
      done
    adb logcat -b all -c
  done
}

# Before any tapping: does the app start and show its first screen?
adb shell monkey -p "$APP" -c android.intent.category.LAUNCHER 1 > /dev/null 2>&1
sleep 25
adb exec-out screencap -p > "$OUT/shots/00-first-launch.png" 2>/dev/null
adb logcat -d > "$OUT/00-first-launch-logcat.txt" 2>/dev/null
adb shell am force-stop "$APP"
adb logcat -b all -c

adb shell cmd uimode night no
run_flows light
adb shell cmd uimode night yes
run_flows dark

# Maestro's own debug output (its screenshots of failed steps, and logs).
[ -d "$HOME/.maestro/tests" ] && cp -r "$HOME/.maestro/tests" "$OUT/maestro-debug"

echo "$(ls "$OUT/shots" | wc -l) screenshots" | tee -a "$OUT/summary.txt"

# A crash anywhere fails the check.
if ls "$OUT"/*-crash.txt > /dev/null 2>&1; then
  echo "The app crashed (see the *-crash.txt files)." | tee -a "$OUT/summary.txt"
  exit 1
fi
exit 0
