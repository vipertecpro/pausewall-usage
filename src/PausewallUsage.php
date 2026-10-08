<?php

namespace Vipertecpro\PausewallUsage;

use Illuminate\Support\Collection;
use Vipertecpro\PausewallUsage\Data\UsageDay;
use Vipertecpro\PausewallUsage\Enums\PermissionStatus;

/**
 * Read-only app usage: how long each app was used, per day.
 *
 *     use Vipertecpro\PausewallUsage\Facades\PausewallUsage;
 *
 *     PausewallUsage::requestPermission();          // after your own disclosure screen
 *     PausewallUsage::today()->totalSeconds;
 *     PausewallUsage::usage(7);                      // the last 7 days, newest first
 *
 * Android only. iOS usage reports need an app extension, which a plugin cannot ship, so on
 * iOS every call reports `unsupportedOnIos` through lastReason(). Off-device (a browser, a
 * test) every method returns a safe default and `isAvailable()` is false. Nothing runs in
 * the background and nothing leaves the device: the plugin makes no network calls.
 */
class PausewallUsage
{
    /** Why the last call returned nothing: `permissionMissing`, `unsupportedOnIos`, `alreadyGranted`, `noSettingsScreen`. */
    protected ?string $lastReason = null;

    public function permission(): PermissionStatus
    {
        $this->lastReason = null;
        $response = $this->call('PausewallUsage.Permission');

        if ($response === null) {
            return PermissionStatus::Unsupported;
        }

        $this->lastReason = $this->reasonFrom($response);

        return PermissionStatus::fromPayload(
            is_string($response['status'] ?? null) ? $response['status'] : null,
            PermissionStatus::Unsupported,
        );
    }

    /**
     * Open the Usage access settings for this app. Show your own prominent disclosure first;
     * this method does not. Check permission() when the app resumes to see the outcome.
     */
    public function requestPermission(): bool
    {
        $this->lastReason = null;
        $response = $this->call('PausewallUsage.RequestPermission');

        if (! ($response['opened'] ?? false)) {
            $this->lastReason = $this->reasonFrom($response ?? []);

            return false;
        }

        return true;
    }

    /**
     * Usage for the last 1 to 7 local days, newest first. Empty without Usage access
     * (lastReason() is `permissionMissing`), on iOS and off-device.
     *
     * @return Collection<int, UsageDay>
     */
    public function usage(int $days = 1): Collection
    {
        $this->lastReason = null;
        $response = $this->call('PausewallUsage.Usage', ['days' => max(1, min(7, $days))]);
        $this->lastReason = $this->reasonFrom($response ?? []);

        return collect((array) ($response['days'] ?? []))
            ->filter(fn (mixed $item) => is_array($item))
            ->map(fn (array $day) => UsageDay::fromArray($day))
            ->values();
    }

    /**
     * Today's total and the $limit most used apps (1 to 50). The total counts every app,
     * not only the ones listed. An empty day without Usage access, on iOS and off-device.
     */
    public function today(int $limit = 10): UsageDay
    {
        $this->lastReason = null;
        $response = $this->call('PausewallUsage.Today', ['limit' => max(1, min(50, $limit))]);
        $this->lastReason = $this->reasonFrom($response ?? []);

        $day = UsageDay::fromArray(is_array($response) ? $response : []);

        return $day->date === '' ? new UsageDay(date('Y-m-d')) : $day;
    }

    public function isAvailable(): bool
    {
        return function_exists('nativephp_call');
    }

    /** The reason the last call returned nothing, or null. */
    public function lastReason(): ?string
    {
        return $this->lastReason;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function reasonFrom(array $response): ?string
    {
        return isset($response['reason']) ? (string) $response['reason'] : null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null Null off-device.
     */
    protected function call(string $method, array $params = []): ?array
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $raw = nativephp_call($method, json_encode((object) $params));

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
