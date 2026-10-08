#!/usr/bin/env bash
#
# Typecheck the Pausewall Usage iOS sources against the real iOS simulator SDK.
#
# The bridge functions reference symbols the NativePHP host app provides
# (BridgeFunction, BridgeResponse), so this compiles them
# alongside minimal stubs for those. Swift 5 language mode matches the host
# project (SWIFT_VERSION = 5.0); warnings fail the check. Catches real
# compile errors without a full app build.
#
# Usage: tests/native/ios/typecheck.sh
#
set -euo pipefail

plugin_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT

if ! command -v xcrun >/dev/null 2>&1; then
    echo "xcrun not found: install Xcode to run this check." >&2
    exit 1
fi

sdk="$(xcrun --sdk iphonesimulator --show-sdk-path)"
arch="$(uname -m)"
[ "$arch" = "arm64" ] || arch="x86_64"

cat > "$work_dir/HostStubs.swift" <<'SWIFT'
import Foundation

// Minimal stand-ins for the symbols the NativePHP host app provides, with
// the same signatures as nativephp/mobile's BridgeRouter.swift.
protocol BridgeFunction {
    func execute(parameters: [String: Any]) throws -> [String: Any]
}

struct BridgeResponse {
    static func success(data: [String: Any] = [:]) -> [String: Any] { data }
    static func error(code: String, message: String, data: [String: Any] = [:]) -> [String: Any] {
        ["status": "error", "code": code, "message": message, "data": data]
    }
}

// What the generated PluginBridgeFunctionRegistration.swift emits for this
// plugin's manifest, so a wrong class name fails here.
func registerPluginBridgeFunctionsCheck() {
    let functions: [String: BridgeFunction] = [
        "PausewallUsage.Permission": PausewallUsageFunctions.Permission(),
        "PausewallUsage.RequestPermission": PausewallUsageFunctions.RequestPermission(),
        "PausewallUsage.Usage": PausewallUsageFunctions.Usage(),
        "PausewallUsage.Today": PausewallUsageFunctions.Today(),
    ]
    _ = functions
}
SWIFT

swiftc -typecheck \
    -sdk "$sdk" \
    -target "$arch-apple-ios18.0-simulator" \
    -swift-version 5 \
    -warnings-as-errors \
    "$work_dir/HostStubs.swift" \
    "$plugin_root"/resources/ios/Sources/*.swift

echo "Pausewall Usage iOS sources typecheck against the iOS $(xcrun --sdk iphonesimulator --show-sdk-version) simulator SDK (deployment target 18.0)."
