<?php

namespace Vipertecpro\PausewallUsage\Support;

/**
 * Lets a backed enum accept `notDetermined`, `not_determined` and `NotDetermined` alike.
 */
trait NormalizesEnumValues
{
    public static function fromPayload(string|self|null $value, ?self $default = null): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $needle = strtolower(str_replace(['_', '-', ' '], '', (string) $value));

        foreach (self::cases() as $case) {
            if (strtolower($case->value) === $needle) {
                return $case;
            }
        }

        return $default ?? throw new \ValueError("\"{$value}\" is not a valid ".self::class.' value.');
    }
}
