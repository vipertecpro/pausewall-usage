# Changelog

All notable changes to `vipertecpro/pausewall-usage` are documented here.

The format follows Keep a Changelog, and this project adheres to Semantic Versioning.

## [1.0.0] - 2026-10-08

### Added

- A PHP API (`PausewallUsage` facade): `permission()`, `requestPermission()`,
  `usage()`, `today()`, `isAvailable()` and `lastReason()`; 4 bridge functions in all.
- `usage(1..7)`: foreground time and opens per app for each local day, newest day first,
  apps sorted by time, with app names. Counted the same way as Pausewall for NativePHP.
- `today()`: today's total foreground time and the most used apps.
- Readonly `UsageDay` and `AppUsage` objects, and a `PermissionStatus` enum.
- Android: reads Android's usage statistics once per call. No service, no receiver,
  nothing in the background, no new Gradle dependency. Declares only the Usage access
  permission.
- iOS: every call reports `unsupportedOnIos`, because iOS usage reports need an app
  extension that a plugin cannot ship.
