<?php

declare(strict_types=1);

namespace dev\winterframework\scim\protocol;

/** Case-insensitive access to the keys of a SCIM JSON object (RFC 7643 §2.1). */
final class Keys {
    /** The key of $array that spells $name in any case, or null. */
    public static function find(array $array, string $name): ?string {
        if (array_key_exists($name, $array)) {
            return $name;
        }
        foreach ($array as $key => $_) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return $key;
            }
        }
        return null;
    }

    public static function get(array $array, string $name): mixed {
        $key = self::find($array, $name);
        return $key === null ? null : $array[$key];
    }
}
