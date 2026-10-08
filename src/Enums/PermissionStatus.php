<?php

namespace Vipertecpro\PausewallUsage\Enums;

use Vipertecpro\PausewallUsage\Support\NormalizesEnumValues;

enum PermissionStatus: string
{
    use NormalizesEnumValues;

    case Granted = 'granted';
    case Denied = 'denied';
    case NotDetermined = 'notDetermined';
    case Unsupported = 'unsupported';
}
