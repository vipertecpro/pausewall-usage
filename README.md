# Pausewall Usage for NativePHP

Find out how long each app was used, from PHP. Ask for today's total and the most
used apps, or for the last 7 days, newest first, with the name, the foreground time
and the number of opens of every app. That is all it does. It is read-only: it cannot
block, limit or change anything, and it runs only when you call it. No service, no
receiver, nothing in the background, no new Gradle dependency, **and no data leaves the
device**: the plugin makes no network calls at all.

Free and MIT licensed. It is the small, read-only companion to Pausewall for NativePHP,
which also blocks apps (see the last section).

**Android only.** iOS does not let an app read how long other apps were used. Apple
offers that only through Screen Time reports, which need an app extension that a
plugin cannot ship. On iOS every call answers plainly with `unsupportedOnIos`
(see `lastReason()`), so you can build one screen for both platforms.

```php
use Vipertecpro\PausewallUsage\Facades\PausewallUsage;

PausewallUsage::requestPermission();      // after your own disclosure screen
$today = PausewallUsage::today();         // total seconds and the 10 most used apps
$week  = PausewallUsage::usage(7);        // 7 days, newest first
```

## What is included

| Method | What you get |
| --- | --- |
| `permission()` | The Usage access status as a `PermissionStatus`: `Granted`, `Denied`, `NotDetermined` or `Unsupported`. |
| `requestPermission()` | Opens the Usage access settings for your app and returns `true` when the screen opened. Check `permission()` again when the app resumes. |
| `usage(int $days = 1)` | A collection of `UsageDay`, newest first, for 1 to 7 local days. Apps are sorted by time, longest first. |
| `today(int $limit = 10)` | One `UsageDay` for today: the total of every app and the `$limit` most used apps (1 to 50). |
| `isAvailable()` | `true` on a phone, `false` in a browser or a test. |
| `lastReason()` | Why the last call returned nothing: `permissionMissing`, `unsupportedOnIos`, `alreadyGranted` or `noSettingsScreen`. |

`UsageDay` is a readonly object with `date` (YYYY-MM-DD, phone local time),
`totalSeconds`, `apps` (a list of `AppUsage`), `topApp()` and `toArray()`.
`AppUsage` is a readonly object with `id` (the package name), `name` (the app's label
on the phone), `foregroundSeconds` and `opens`, plus `fromArray()` and `toArray()`.

Time is counted the way Pausewall for NativePHP counts it: one app is in the
foreground at a time, turning the screen off ends it, an app that was open at midnight
counts from midnight, and an "open" is a switch into an app from a different app or
from screen off. Only apps with a launcher icon are listed, and only those used for at
least a second or opened at least once.

Off-device (a browser, a test, a CI run) every method returns a safe default: an
`Unsupported` permission, `false`, an empty collection, and an empty day dated today.

## Install

```bash
composer require vipertecpro/pausewall-usage
php artisan native:plugin:register vipertecpro/pausewall-usage
```

Then build your app as usual. Requires PHP 8.4 or newer, `nativephp/mobile` 4.6 or
newer, and Android 10 (API 29) or newer.

The plugin declares one Android permission, Usage access (`PACKAGE_USAGE_STATS`), and
a query for apps that have a launcher icon, so it can show their names. It declares no
accessibility service, no overlay, no foreground service, no receiver and no
notification permission.

## Quick start

```php
use Vipertecpro\PausewallUsage\Enums\PermissionStatus;
use Vipertecpro\PausewallUsage\Facades\PausewallUsage;

if (PausewallUsage::permission() !== PermissionStatus::Granted) {
    // Show your own screen that explains why, then, when the user agrees:
    PausewallUsage::requestPermission();

    return; // the user turns Usage access on in Settings; ask again on resume
}

$today = PausewallUsage::today(5);

echo "Today: ".gmdate('G\h i\m', $today->totalSeconds);

foreach ($today->apps as $app) {
    echo "{$app->name}: ".intdiv($app->foregroundSeconds, 60)." min, {$app->opens} opens";
}

foreach (PausewallUsage::usage(7) as $day) {
    echo "{$day->date}: {$day->totalSeconds}s, top app {$day->topApp()?->name}";
}
```

Usage access is not a pop-up permission. The user turns it on for your app in
Settings, so `requestPermission()` takes them to that screen. Re-check `permission()`
when your app comes back to the front. Until it is `Granted`, `usage()` and `today()`
return empty results and `lastReason()` is `permissionMissing`.

## What you can build

Pausewall Usage is a building block: reading the numbers is done, and the screen
around them is yours. Ask for Usage access only when showing app usage is part of
what your app is for, show your disclosure first (see the Google Play section), and
keep the numbers on the phone unless the person chooses to share them.

**Habits and wellbeing**

- **Screen-time dashboards.** Show today's total with `today()`, the most used apps
  with their opens, and a 7-day trend from `usage(7)`, all on one screen.
- **Habit goals.** Let people set a goal such as "under an hour of social apps a day"
  or "fewer than 20 opens", then compare it with `today()` and show their progress.
- **Wellbeing journals.** Put today's numbers next to a short note or mood the person
  writes each evening, so they can see how the two relate over time.
- **Weekly summaries.** Build a Sunday recap that compares each day of the week and
  names the top app. The plugin reads up to 7 days, so save each day in your app's own
  database on the phone if you want a longer history.

**Focus and study**

- **Study trackers for students.** Compare time in study and reading apps with time in
  games and video apps over the week, and celebrate the good days.
- **Focus-session check-ins.** After a focus timer ends, show how many times the person
  opened a distracting app that day, using the `opens` count.

**Families and coaching**

- **Family screen-time check-ins on the child's own phone.** The child installs the app,
  sees their own numbers and agrees to what is shown. Sharing with a parent's phone
  needs your own backend and the child's knowledge; never hide the app or collect
  quietly.
- **Digital-wellbeing coaching.** A person shares their weekly summary with a coach when
  they choose to. Sending it anywhere needs your own backend and explicit consent.

When people want to act on the numbers, not just read them, Pausewall for NativePHP adds
blocking, limits and schedules with the same counting rules (see the last section).
Everything here is Android only; on iOS every call answers `unsupportedOnIos`.

## Google Play

Usage access shows which apps a person uses and for how long, which Google Play treats
as sensitive user data. Before you publish an app that asks for it:

- Show a prominent in-app disclosure before you call `requestPermission()`. It must be
  in the normal flow of your app, on its own screen or dialog, say what is read (which
  apps are used and for how long), why you need it, and that it stays on the device, and
  it needs an explicit button to continue and a way to decline. The store listing or the
  privacy policy alone do not count.
- Complete the permissions declaration in Play Console for the Usage access permission
  and explain the core purpose of your app.
- Describe it in your privacy policy and in the Data safety form.

This plugin reads the usage statistics on the phone when you call it and returns them to
your PHP code on the phone. It never sends anything anywhere. What your own app does
with the numbers afterwards is up to you, and it is what the disclosure and the Data
safety form must describe. This is guidance, not legal advice: read the current policy
text in Play Console before you submit.

## Testing

The package is tested with Pest. In your own app, answer the bridge call in your test,
or let it fall back to the off-device defaults:

```php
use Vipertecpro\PausewallUsage\Facades\PausewallUsage;

expect(PausewallUsage::isAvailable())->toBeFalse();
expect(PausewallUsage::usage(7))->toBeEmpty();
expect(PausewallUsage::today()->totalSeconds)->toBe(0);
```

The Kotlin source is checked against the Android SDK with `tests/native/android/typecheck.sh`
in the repository, and the Swift source against the iOS SDK with `tests/native/ios/typecheck.sh`.

## Want to block apps too?

Pausewall for NativePHP is the full plugin this one is a small part of. It does not just
read usage, it acts on it: focus sessions that block (or allow only) the apps you choose,
daily limits by time or by number of opens, weekly schedules, short breaks behind some
friction, app categories, and your own block screen with a live countdown. It is tested on
Android; the iOS part is a preview. It is available on vipertecpro.com, and it reads usage
with the same counting rules as this plugin, so your numbers will match.

## Trademarks and independence

vipertecpro is an independent developer. Pausewall Usage for NativePHP is not affiliated
with or endorsed by NativePHP, Apple or Google. Apple, iOS and Screen Time are trademarks
of Apple Inc., registered in the U.S. and other countries. Android and Google Play are
trademarks of Google LLC. NativePHP is a trademark of Bifrost Technology. Laravel is a
trademark of Laravel Holdings Inc. All are named here only to describe what the plugin
works with.

## Licence

MIT. Copyright (c) 2026 Vipul Walia (vipertecpro).
