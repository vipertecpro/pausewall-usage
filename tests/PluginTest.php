<?php

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Vipertecpro\PausewallUsage\Facades\PausewallUsage as PausewallUsageFacade;
use Vipertecpro\PausewallUsage\PausewallUsage;
use Vipertecpro\PausewallUsage\PausewallUsageServiceProvider;

beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifest = json_decode(file_get_contents($this->pluginPath.'/nativephp.json'), true);
    $this->kotlin = file_get_contents($this->pluginPath.'/resources/android/src/PausewallUsageFunctions.kt');
    $this->swift = file_get_contents($this->pluginPath.'/resources/ios/Sources/PausewallUsageFunctions.swift');
});

/** The parameters each bridge function sends. */
const BRIDGE_PARAMS = [
    'Permission' => [],
    'RequestPermission' => [],
    'Usage' => ['days'],
    'Today' => ['limit'],
];

describe('manifest', function () {
    it('has the identity of a free MIT plugin', function () {
        expect($this->manifest['name'])->toBe('vipertecpro/pausewall-usage');
        expect($this->manifest['namespace'])->toBe('PausewallUsage');
        expect($this->manifest['version'])->toBe('1.0.0');
        expect($this->manifest['pricing']['type'])->toBe('free');
        expect($this->manifest['license'])->toBe('MIT');
        expect($this->manifest['platforms'])->toBe(['android', 'ios']);
        expect($this->manifest['android']['min_version'])->toBe(29);
        expect($this->manifest['icon'])->toBe('resources/icon.png');
        expect($this->manifest['service_provider'])->toBe(PausewallUsageServiceProvider::class);
        expect($this->manifest['homepage'])->toBe('https://vipertecpro.com/products/pausewall');
        expect(file_exists($this->pluginPath.'/'.$this->manifest['icon']))->toBeTrue();
    });

    it('agrees with composer.json', function () {
        $composer = json_decode(file_get_contents($this->pluginPath.'/composer.json'), true);

        expect($composer['name'])->toBe($this->manifest['name']);
        expect($composer['type'])->toBe('nativephp-plugin');
        expect($composer['license'])->toBe('MIT');
        expect($composer['description'])->toBe($this->manifest['description']);
        expect($composer['homepage'])->toBe($this->manifest['homepage']);
        expect($composer['keywords'])->toBe($this->manifest['keywords']);
        expect($composer['require'])->toBe(['php' => '^8.4', 'nativephp/mobile' => '^4.6']);
        expect($composer['autoload']['psr-4'])->toBe(['Vipertecpro\\PausewallUsage\\' => 'src/']);
        expect($composer['extra']['laravel']['providers'])->toBe([$this->manifest['service_provider']]);
        expect($composer['extra']['laravel']['aliases'])->toBe(['PausewallUsage' => PausewallUsageFacade::class]);
    });

    it('declares every bridge function on both platforms', function () {
        $names = array_column($this->manifest['bridge_functions'], 'name');

        expect($names)->toEqualCanonicalizing(array_map(fn ($short) => "PausewallUsage.{$short}", array_keys(BRIDGE_PARAMS)));

        foreach ($this->manifest['bridge_functions'] as $function) {
            $short = substr($function['name'], strlen('PausewallUsage.'));

            expect($function['ios'])->toBe("PausewallUsageFunctions.{$short}");
            expect($function['android'])->toBe("com.vipertecpro.plugins.pausewallusage.PausewallUsageFunctions.{$short}");
            expect($function['description'])->not->toBe('')->not->toContain('TODO');
        }
    });

    it('has a native class for every declared function', function () {
        expect($this->kotlin)->toContain('package com.vipertecpro.plugins.pausewallusage');
        expect($this->swift)->toContain('enum PausewallUsageFunctions');

        foreach (array_keys(BRIDGE_PARAMS) as $short) {
            expect($this->kotlin)->toContain("class {$short}(private val activity: FragmentActivity) : BridgeFunction");
            expect($this->swift)->toContain("class {$short}: BridgeFunction");
        }
    });
});

describe('lightweight by design', function () {
    it('declares only the usage access permission and the launcher query on Android', function () {
        $android = $this->manifest['android'];

        expect($android['permissions'])->toHaveCount(2);
        expect($android['permissions'][0])->toBe('<uses-permission android:name="android.permission.PACKAGE_USAGE_STATS" tools:ignore="ProtectedPermissions" />');
        expect($android['permissions'][1])->toStartWith('<queries><intent>')->toContain('android.intent.category.LAUNCHER');

        $all = json_encode($android);
        foreach (['SYSTEM_ALERT_WINDOW', 'FOREGROUND_SERVICE', 'BIND_ACCESSIBILITY_SERVICE', 'RECEIVE_BOOT_COMPLETED', 'POST_NOTIFICATIONS', 'INTERNET'] as $forbidden) {
            expect($all)->not->toContain($forbidden);
        }
    });

    it('registers no component, no dependency, no hook and no event', function () {
        $android = $this->manifest['android'];

        expect($android['activities'])->toBe([]);
        expect($android['services'])->toBe([]);
        expect($android['receivers'])->toBe([]);
        expect($android['providers'])->toBe([]);
        expect($android['dependencies'])->toBe(['implementation' => []]);
        expect($android)->not->toHaveKey('init_function');
        expect($this->manifest['ios'])->not->toHaveKey('init_function');
        expect($this->manifest['ios']['entitlements'])->toBe([]);
        expect($this->manifest['events'])->toBe([]);
        expect($this->manifest['components'])->toBe([]);
        expect($this->manifest['secrets'])->toBe([]);
        expect($this->manifest)->not->toHaveKey('hooks');
        expect(file_exists($this->pluginPath.'/resources/android/AndroidManifest.xml'))->toBeFalse();
    });

    it('has no service, receiver or network code in the Android sources', function () {
        $sources = '';
        foreach (glob($this->pluginPath.'/resources/android/src/*.kt') as $file) {
            $sources .= file_get_contents($file);
        }

        foreach (['Service()', ': Service', 'BroadcastReceiver', 'startForeground', 'AccessibilityService', 'WorkManager', 'AlarmManager', 'java.net', 'HttpURLConnection', 'okhttp'] as $forbidden) {
            expect($sources)->not->toContain($forbidden);
        }
    });
});

describe('payload contract', function () {
    it('has PHP send exactly the parameters the native side reads', function () {
        $usage = usage();
        $usage->permission();
        $usage->requestPermission();
        $usage->usage();
        $usage->today();

        $sent = [];
        foreach (calls() as $call) {
            $sent[substr($call['name'], strlen('PausewallUsage.'))] = array_keys($call['payload']);
        }

        expect(array_keys($sent))->toEqualCanonicalizing(array_keys(BRIDGE_PARAMS));

        foreach (BRIDGE_PARAMS as $method => $keys) {
            expect($sent[$method])->toEqualCanonicalizing($keys, $method);
        }

        expect($this->kotlin)->toContain('parameters["days"]')->toContain('parameters["limit"]');
    });

    it('has the Android side answer with the keys the PHP DTOs read', function () {
        foreach (['"status"', '"opened"', '"reason"', '"days"', '"date"', '"totalSeconds"', '"apps"', '"id"', '"name"', '"foregroundSeconds"', '"opens"'] as $key) {
            expect($this->kotlin)->toContain($key);
        }

        foreach (['permissionMissing', 'alreadyGranted', 'noSettingsScreen'] as $reason) {
            expect($this->kotlin)->toContain("\"{$reason}\"");
        }

        foreach (['granted', 'denied', 'notDetermined'] as $status) {
            expect($this->kotlin)->toContain("\"{$status}\"");
        }
    });

    it('has the iOS side answer unsupportedOnIos in the same shapes', function () {
        expect($this->swift)->toContain('"unsupportedOnIos"');
        expect($this->swift)->toContain('"status": "unsupported"');
        expect($this->swift)->toContain('"opened": false');
        expect($this->swift)->toContain('"days": [Any]()');
        expect($this->swift)->toContain('"totalSeconds": 0')->toContain('"apps": [Any]()');
    });

    it('reads the iOS answers into safe PHP defaults', function () {
        bridgeAnswers(['days' => [], 'reason' => 'unsupportedOnIos']);
        $usage = usage();

        expect($usage->usage(3))->toBeEmpty();
        expect($usage->lastReason())->toBe('unsupportedOnIos');
    });
});

describe('service provider and facade', function () {
    it('binds a singleton the facade resolves', function () {
        $app = new Container;
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        (new PausewallUsageServiceProvider($app))->register();

        expect($app->make(PausewallUsage::class))->toBe($app->make(PausewallUsage::class));

        bridgeAnswers(dayPayload());
        expect(PausewallUsageFacade::today()->totalSeconds)->toBe(5400);
        expect(PausewallUsageFacade::isAvailable())->toBeTrue();

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    });

    it('documents every public method on the facade', function () {
        $docblock = (new ReflectionClass(PausewallUsageFacade::class))->getDocComment();

        foreach ((new ReflectionClass(PausewallUsage::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            expect($docblock)->toContain($method->getName().'(');
        }
    });
});

describe('export', function () {
    it('keeps tests out of the installed package', function () {
        $attributes = file_get_contents($this->pluginPath.'/.gitattributes');

        expect($attributes)->toContain('/tests export-ignore');
    });

    it('is MIT licensed to the author', function () {
        $licence = file_get_contents($this->pluginPath.'/LICENSE');

        expect($licence)->toStartWith('MIT License')->toContain('Copyright (c) 2026 Vipul Walia (vipertecpro)');
    });
});
