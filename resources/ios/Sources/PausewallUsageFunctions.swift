// Pausewall Usage for NativePHP: iOS.
//
// iOS does not let an app read how long other apps were used. Apple only offers that through
// Screen Time reports, which need a DeviceActivityReport app extension that a plugin cannot
// ship. So every function answers plainly with `unsupportedOnIos` and does nothing else.

import Foundation

enum PausewallUsageFunctions {
    fileprivate static let reason = "unsupportedOnIos"

    /// `{}` → `{status:"unsupported", reason:"unsupportedOnIos"}`.
    class Permission: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            BridgeResponse.success(data: ["status": "unsupported", "reason": PausewallUsageFunctions.reason])
        }
    }

    /// `{}` → `{opened:false, reason:"unsupportedOnIos"}`.
    class RequestPermission: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            BridgeResponse.success(data: ["opened": false, "reason": PausewallUsageFunctions.reason])
        }
    }

    /// `{days}` → `{days:[], reason:"unsupportedOnIos"}`.
    class Usage: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            BridgeResponse.success(data: ["days": [Any](), "reason": PausewallUsageFunctions.reason])
        }
    }

    /// `{limit?}` → `{date, totalSeconds:0, apps:[], reason:"unsupportedOnIos"}`.
    class Today: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let formatter = DateFormatter()
            formatter.calendar = Calendar(identifier: .gregorian)
            formatter.locale = Locale(identifier: "en_US_POSIX")
            formatter.dateFormat = "yyyy-MM-dd"
            return BridgeResponse.success(data: [
                "date": formatter.string(from: Date()),
                "totalSeconds": 0,
                "apps": [Any](),
                "reason": PausewallUsageFunctions.reason,
            ])
        }
    }
}
