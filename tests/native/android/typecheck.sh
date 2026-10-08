#!/usr/bin/env bash
#
# Typecheck Pausewall Usage's Android sources (resources/android/src/*.kt) against the Android SDK
# and androidx.lifecycle, with minimal stubs for what the NativePHP host app
# provides (BridgeFunction, BridgeResponse, FragmentActivity).
# Catches real compile errors without a Gradle project or a device build. The plugin itself
# adds no Gradle dependencies: androidx already ships in the NativePHP host app.
#
# Needs: kotlinc (on PATH, or the one bundled with Android Studio), an Android SDK platform
# (ANDROID_HOME or ~/Library/Android/sdk). The androidx jar is taken from the local Gradle
# cache when present, otherwise fetched once from Google's Maven repository into
# ~/.cache/vipertecpro-pausewall-usage.
#
# Usage: tests/native/android/typecheck.sh
#
set -euo pipefail

LIFECYCLE_VERSION="2.9.4"
MAVEN="https://dl.google.com/dl/android/maven2"

plugin_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT
cache_dir="${XDG_CACHE_HOME:-$HOME/.cache}/vipertecpro-pausewall-usage"
gradle_cache="${GRADLE_USER_HOME:-$HOME/.gradle}/caches/modules-2/files-2.1"
mkdir -p "$cache_dir"

# ── Kotlin compiler ────────────────────────────────────────────
kotlinc_bin="$(command -v kotlinc || true)"
if [[ -z "$kotlinc_bin" ]]; then
    for candidate in \
        "$HOME/Applications/Android Studio.app/Contents/plugins/Kotlin/kotlinc/bin/kotlinc" \
        "/Applications/Android Studio.app/Contents/plugins/Kotlin/kotlinc/bin/kotlinc"; do
        if [[ -x "$candidate" ]]; then kotlinc_bin="$candidate"; break; fi
    done
fi
if [[ -z "$kotlinc_bin" ]]; then
    echo "kotlinc not found: install Kotlin (brew install kotlin) or Android Studio to run this check." >&2
    exit 1
fi
if [[ -z "${JAVA_HOME:-}" ]] && ! command -v java >/dev/null 2>&1; then
    studio_jbr="$(dirname "$(dirname "$(dirname "$(dirname "$(dirname "$kotlinc_bin")")")")")/jbr/Contents/Home"
    [[ -d "$studio_jbr" ]] && export JAVA_HOME="$studio_jbr"
fi

# ── Android SDK ────────────────────────────────────────────────
sdk="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-$HOME/Library/Android/sdk}}"
android_jar="$(ls -d "$sdk"/platforms/android-*/android.jar 2>/dev/null | sort -V | tail -1 || true)"
if [[ -z "$android_jar" ]]; then
    echo "No Android SDK platform found under $sdk/platforms." >&2
    exit 1
fi

# ── androidx.lifecycle (FragmentActivity implements LifecycleOwner) ─
lifecycle_jar="$cache_dir/lifecycle-common-jvm-$LIFECYCLE_VERSION.jar"
if [[ ! -f "$lifecycle_jar" ]]; then
    cached="$(find "$gradle_cache/androidx.lifecycle/lifecycle-common-jvm/$LIFECYCLE_VERSION" -name "lifecycle-common-jvm-$LIFECYCLE_VERSION.jar" 2>/dev/null | head -1 || true)"
    if [[ -n "$cached" ]]; then
        cp "$cached" "$lifecycle_jar"
    else
        curl -sSfL "$MAVEN/androidx/lifecycle/lifecycle-common-jvm/$LIFECYCLE_VERSION/lifecycle-common-jvm-$LIFECYCLE_VERSION.jar" -o "$lifecycle_jar"
    fi
fi

# ── Host stubs ─────────────────────────────────────────────────
mkdir -p "$work_dir/stubs"
cat > "$work_dir/stubs/HostStubs.kt" <<'KOTLIN'
// Minimal stand-ins for the symbols the NativePHP host project provides.
// Signatures copied from nativephp/mobile resources/androidstudio (BridgeRouter.kt).
package com.nativephp.mobile.bridge

interface BridgeFunction {
    fun execute(parameters: Map<String, Any>): Map<String, Any>
}

object BridgeResponse {
    fun success(data: Map<String, Any> = emptyMap()): Map<String, Any> = data
    fun error(code: String, message: String, data: Map<String, Any> = emptyMap()): Map<String, Any> =
        mapOf("status" to "error", "code" to code, "message" to message, "data" to data)
}
KOTLIN
cat > "$work_dir/stubs/FragmentActivityStub.kt" <<'KOTLIN'
// androidx.fragment's FragmentActivity, reduced to what the plugin uses.
package androidx.fragment.app

import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleOwner

open class FragmentActivity : android.app.Activity(), LifecycleOwner {
    override val lifecycle: Lifecycle get() = throw UnsupportedOperationException()
}
KOTLIN

# Deprecation warnings are muted; any real compile error fails the check.
"$kotlinc_bin" \
    -classpath "$android_jar:$lifecycle_jar" \
    -jvm-target 17 \
    -nowarn \
    -d "$work_dir/out" \
    "$work_dir"/stubs/*.kt \
    "$plugin_root"/resources/android/src/*.kt

echo "Pausewall Usage Android sources compile against $(basename "$(dirname "$android_jar")") and androidx.lifecycle $LIFECYCLE_VERSION."
