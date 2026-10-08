<?php

use Vipertecpro\PausewallUsage\Data\UsageDay;
use Vipertecpro\PausewallUsage\Enums\PermissionStatus;

describe('permission()', function () {
    it('reports each status the bridge can answer', function (string $status, PermissionStatus $expected) {
        bridgeAnswers(['status' => $status]);

        expect(usage()->permission())->toBe($expected);
        expect(lastCall())->toBe(['PausewallUsage.Permission', []]);
    })->with([
        ['granted', PermissionStatus::Granted],
        ['denied', PermissionStatus::Denied],
        ['notDetermined', PermissionStatus::NotDetermined],
        ['unsupported', PermissionStatus::Unsupported],
    ]);

    it('is unsupported for an unknown or missing status', function () {
        bridgeAnswers(['status' => 'whatever']);
        expect(usage()->permission())->toBe(PermissionStatus::Unsupported);

        bridgeAnswers([]);
        expect(usage()->permission())->toBe(PermissionStatus::Unsupported);
    });

    it('exposes the iOS reason', function () {
        bridgeAnswers(['status' => 'unsupported', 'reason' => 'unsupportedOnIos']);
        $usage = usage();

        expect($usage->permission())->toBe(PermissionStatus::Unsupported);
        expect($usage->lastReason())->toBe('unsupportedOnIos');
    });
});

describe('requestPermission()', function () {
    it('returns true when the settings screen opened', function () {
        bridgeAnswers(['opened' => true]);
        $usage = usage();

        expect($usage->requestPermission())->toBeTrue();
        expect($usage->lastReason())->toBeNull();
        expect(lastCall())->toBe(['PausewallUsage.RequestPermission', []]);
    });

    it('returns false with the reason when nothing opened', function (string $reason) {
        bridgeAnswers(['opened' => false, 'reason' => $reason]);
        $usage = usage();

        expect($usage->requestPermission())->toBeFalse();
        expect($usage->lastReason())->toBe($reason);
    })->with(['alreadyGranted', 'noSettingsScreen', 'unsupportedOnIos']);

    it('sends an empty JSON object, not a list', function () {
        usage()->requestPermission();

        expect(calls()[0]['raw'])->toBe('{}');
    });
});

describe('usage()', function () {
    it('returns the days newest first as UsageDay objects', function () {
        bridgeAnswers(['days' => [
            dayPayload(),
            dayPayload(['date' => '2026-10-07', 'totalSeconds' => 60, 'apps' => [['id' => 'a.b', 'name' => 'A', 'foregroundSeconds' => 60, 'opens' => 1]]]),
        ]]);

        $days = usage()->usage(2);

        expect($days)->toHaveCount(2);
        expect($days[0])->toBeInstanceOf(UsageDay::class);
        expect($days->pluck('date')->all())->toBe(['2026-10-08', '2026-10-07']);
        expect($days[0]->apps[0]->name)->toBe('Chrome');
        expect($days[0]->apps[0]->foregroundSeconds)->toBe(3600);
        expect($days[0]->apps[1]->opens)->toBe(5);
    });

    it('defaults to one day and clamps the range to 1..7', function () {
        $usage = usage();

        $usage->usage();
        expect(lastCall()[1])->toBe(['days' => 1]);

        $usage->usage(0);
        expect(lastCall()[1])->toBe(['days' => 1]);

        $usage->usage(30);
        expect(lastCall()[1])->toBe(['days' => 7]);

        $usage->usage(3);
        expect(lastCall())->toBe(['PausewallUsage.Usage', ['days' => 3]]);
    });

    it('is empty with a reason when the permission is missing', function () {
        bridgeAnswers(['days' => [], 'reason' => 'permissionMissing']);
        $usage = usage();

        expect($usage->usage(7))->toBeEmpty();
        expect($usage->lastReason())->toBe('permissionMissing');
    });

    it('clears the reason on the next successful call', function () {
        $usage = usage();
        bridgeAnswers(['days' => [], 'reason' => 'permissionMissing']);
        $usage->usage();

        bridgeAnswers(['days' => [dayPayload()]]);
        $usage->usage();

        expect($usage->lastReason())->toBeNull();
    });

    it('skips malformed days', function () {
        bridgeAnswers(['days' => ['nope', dayPayload()]]);

        expect(usage()->usage())->toHaveCount(1);
    });
});

describe('today()', function () {
    it('returns today as a UsageDay', function () {
        bridgeAnswers(dayPayload());

        $today = usage()->today();

        expect($today)->toBeInstanceOf(UsageDay::class);
        expect($today->date)->toBe('2026-10-08');
        expect($today->totalSeconds)->toBe(5400);
        expect($today->topApp()?->id)->toBe('com.android.chrome');
        expect(lastCall())->toBe(['PausewallUsage.Today', ['limit' => 10]]);
    });

    it('passes the app limit, clamped to 1..50', function () {
        $usage = usage();

        $usage->today(3);
        expect(lastCall()[1])->toBe(['limit' => 3]);

        $usage->today(0);
        expect(lastCall()[1])->toBe(['limit' => 1]);

        $usage->today(500);
        expect(lastCall()[1])->toBe(['limit' => 50]);
    });

    it('is an empty day dated today, with a reason, when the permission is missing', function () {
        bridgeAnswers(['date' => '2026-10-08', 'totalSeconds' => 0, 'apps' => [], 'reason' => 'permissionMissing']);
        $usage = usage();
        $today = $usage->today();

        expect($today->totalSeconds)->toBe(0);
        expect($today->apps)->toBe([]);
        expect($today->topApp())->toBeNull();
        expect($usage->lastReason())->toBe('permissionMissing');
    });

    it('fills in the date when the bridge sends none', function () {
        bridgeAnswers(['reason' => 'unsupportedOnIos']);

        $today = usage()->today();

        expect($today->date)->toBe(date('Y-m-d'));
        expect($today->totalSeconds)->toBe(0);
    });
});

describe('isAvailable()', function () {
    it('is true when the bridge function exists', function () {
        expect(usage()->isAvailable())->toBeTrue();
    });

    it('is false off-device', function () {
        expect(offDeviceUsage()->isAvailable())->toBeFalse();
    });
});

describe('off-device defaults', function () {
    it('are safe and never reach the bridge', function () {
        $usage = offDeviceUsage();

        expect($usage->permission())->toBe(PermissionStatus::Unsupported);
        expect($usage->requestPermission())->toBeFalse();
        expect($usage->usage(7))->toBeEmpty();
        expect($usage->today()->totalSeconds)->toBe(0);
        expect($usage->today()->date)->toBe(date('Y-m-d'));
        expect($usage->lastReason())->toBeNull();
        expect(calls())->toBe([]);
    });
});

describe('bridge answers', function () {
    it('survive an empty or invalid response', function (string $raw) {
        bridgeAnswers($raw);
        $usage = usage();

        expect($usage->permission())->toBe(PermissionStatus::Unsupported);
        expect($usage->requestPermission())->toBeFalse();
        expect($usage->usage())->toBeEmpty();
        expect($usage->today()->totalSeconds)->toBe(0);
    })->with(['', 'not json', '"a string"']);
});
