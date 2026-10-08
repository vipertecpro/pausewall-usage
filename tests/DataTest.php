<?php

use Vipertecpro\PausewallUsage\Data\AppUsage;
use Vipertecpro\PausewallUsage\Data\UsageDay;
use Vipertecpro\PausewallUsage\Enums\PermissionStatus;

describe('PermissionStatus', function () {
    it('uses the bridge values', function () {
        expect(array_map(fn ($case) => $case->value, PermissionStatus::cases()))->toBe(['granted', 'denied', 'notDetermined', 'unsupported']);
    });

    it('accepts snake_case and mixed case', function () {
        expect(PermissionStatus::fromPayload('not_determined'))->toBe(PermissionStatus::NotDetermined);
        expect(PermissionStatus::fromPayload('GRANTED'))->toBe(PermissionStatus::Granted);
    });
});

describe('AppUsage', function () {
    it('round-trips through an array', function () {
        $payload = ['id' => 'com.android.chrome', 'name' => 'Chrome', 'foregroundSeconds' => 3600, 'opens' => 12];

        $app = AppUsage::fromArray($payload);

        expect($app->id)->toBe('com.android.chrome');
        expect($app->name)->toBe('Chrome');
        expect($app->foregroundSeconds)->toBe(3600);
        expect($app->opens)->toBe(12);
        expect($app->toArray())->toBe($payload);
    });

    it('reads numbers sent as strings and floors negatives at zero', function () {
        $app = AppUsage::fromArray(['id' => 'a', 'name' => 'A', 'foregroundSeconds' => '90', 'opens' => -2]);

        expect($app->foregroundSeconds)->toBe(90);
        expect($app->opens)->toBe(0);
    });

    it('is readonly', function () {
        $app = new AppUsage('a', 'A');

        expect(fn () => $app->opens = 5)->toThrow(Error::class);
    });

    it('defaults every field', function () {
        $app = AppUsage::fromArray([]);

        expect($app->toArray())->toBe(['id' => '', 'name' => '', 'foregroundSeconds' => 0, 'opens' => 0]);
    });
});

describe('UsageDay', function () {
    it('round-trips through an array', function () {
        $payload = dayPayload();

        $day = UsageDay::fromArray($payload);

        expect($day->date)->toBe('2026-10-08');
        expect($day->totalSeconds)->toBe(5400);
        expect($day->apps)->toHaveCount(2);
        expect($day->apps[0])->toBeInstanceOf(AppUsage::class);
        expect($day->toArray())->toBe($payload);
        expect(UsageDay::fromArray($day->toArray()))->toEqual($day);
    });

    it('adds up the apps when the total is missing', function () {
        $payload = dayPayload();
        unset($payload['totalSeconds']);

        expect(UsageDay::fromArray($payload)->totalSeconds)->toBe(5400);
    });

    it('keeps a total larger than the listed apps (a limited top list)', function () {
        $day = UsageDay::fromArray(dayPayload(['totalSeconds' => 9000]));

        expect($day->totalSeconds)->toBe(9000);
    });

    it('names the most used app', function () {
        expect(UsageDay::fromArray(dayPayload())->topApp()?->name)->toBe('Chrome');
        expect(UsageDay::fromArray(['date' => '2026-10-08'])->topApp())->toBeNull();
    });

    it('is readonly', function () {
        $day = new UsageDay('2026-10-08');

        expect(fn () => $day->totalSeconds = 5)->toThrow(Error::class);
    });
});
