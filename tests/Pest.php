<?php

use Vipertecpro\PausewallUsage\PausewallUsage;

/*
|--------------------------------------------------------------------------
| Stand-in for the native bridge
|--------------------------------------------------------------------------
|
| Everything the plugin SENDS goes through this one function, so recording
| it is how a test sees what the native side would receive, and the canned
| response is how a test feeds it an answer. The real one is injected by
| NativePHP at runtime and does not exist off-device. Off-device behaviour is
| tested through offDeviceUsage(), which never reaches the bridge.
|
*/

if (! function_exists('nativephp_call')) {
    function nativephp_call(string $name, string $payload = ''): string
    {
        $GLOBALS['nativephp_calls'][] = ['name' => $name, 'raw' => $payload, 'payload' => json_decode($payload, true)];

        $responses = $GLOBALS['nativephp_responses'] ?? [];
        $response = $responses[$name] ?? ($GLOBALS['nativephp_response'] ?? []);

        return is_string($response) ? $response : json_encode($response);
    }
}

/** Make the bridge answer `$response` to every call (or to one method). */
function bridgeAnswers(array|string $response, ?string $method = null): void
{
    if ($method === null) {
        $GLOBALS['nativephp_response'] = $response;
        $GLOBALS['nativephp_responses'] = [];

        return;
    }

    $GLOBALS['nativephp_responses'][$method] = $response;
}

/** The last bridge call made, as [name, payload]. */
function lastCall(): array
{
    $last = end($GLOBALS['nativephp_calls']) ?: ['name' => null, 'payload' => []];

    return [$last['name'], $last['payload'] ?? []];
}

function calls(): array
{
    return $GLOBALS['nativephp_calls'] ?? [];
}

function usage(): PausewallUsage
{
    return new PausewallUsage;
}

/**
 * A PausewallUsage that behaves as if no bridge exists (a browser, a CI run): its
 * isAvailable() is false and every call returns the off-device default.
 */
function offDeviceUsage(): PausewallUsage
{
    return new class extends PausewallUsage
    {
        public function isAvailable(): bool
        {
            return false;
        }

        protected function call(string $method, array $params = []): ?array
        {
            return null;
        }
    };
}

/** @return array<string, mixed> */
function dayPayload(array $overrides = []): array
{
    return array_merge([
        'date' => '2026-10-08',
        'totalSeconds' => 5400,
        'apps' => [
            ['id' => 'com.android.chrome', 'name' => 'Chrome', 'foregroundSeconds' => 3600, 'opens' => 12],
            ['id' => 'com.instagram.android', 'name' => 'Instagram', 'foregroundSeconds' => 1800, 'opens' => 5],
        ],
    ], $overrides);
}

uses()->beforeEach(function () {
    $GLOBALS['nativephp_calls'] = [];
    $GLOBALS['nativephp_response'] = [];
    $GLOBALS['nativephp_responses'] = [];
})->in('.');
