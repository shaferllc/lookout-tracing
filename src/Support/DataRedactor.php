<?php

declare(strict_types=1);

namespace Lookout\Tracing\Support;

/**
 * Recursively redact common secret keys from arrays (breadcrumbs, span data, context).
 */
final class DataRedactor
{
    /** @var list<string> */
    private const DEFAULT_KEYS = [
        'password', 'passwd', 'secret', 'api_key', 'apikey', 'api-key',
        'authorization', 'auth', 'token', 'access_token', 'refresh_token',
        'bearer', 'cookie', 'set-cookie', 'credit_card', 'creditcard',
        'card_number', 'cvv', 'ssn', 'private_key',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $extraKeys  Additional key names (case-insensitive) to redact
     * @return array<string, mixed>
     */
    public static function redact(array $data, array $extraKeys = []): array
    {
        $blocked = [];
        foreach (array_merge(self::DEFAULT_KEYS, $extraKeys) as $k) {
            $k = strtolower(trim((string) $k));
            if ($k !== '') {
                $blocked[$k] = true;
            }
        }

        return self::walk($data, $blocked);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, true>  $blocked
     * @return array<string, mixed>
     */
    private static function walk(array $data, array $blocked): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $keyStr = is_string($key) ? $key : (string) $key;
            if (isset($blocked[strtolower($keyStr)])) {
                $out[$keyStr] = '[REDACTED]';

                continue;
            }
            if (is_array($value)) {
                $out[$keyStr] = self::walk($value, $blocked);
            } else {
                $out[$keyStr] = $value;
            }
        }

        return $out;
    }
}
