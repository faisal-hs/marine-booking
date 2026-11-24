<?php

namespace App\Support;

/** Checks a signed HS256 token (the kind Tamara signs its notifications with). Nothing else: it does not make tokens. */
final class Jwt
{
    /**
     * The claims of the token when its signature is right for `$secret` and it has not expired; null otherwise. A token of any other algorithm is refused (so `alg: none` is).
     *
     * @return array<string, mixed>|null
     */
    public static function verifyHs256(string $token, string $secret, int $leewaySeconds = 60): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || $secret === '') {
            return null;
        }

        [$header64, $payload64, $signature64] = $parts;
        $header = json_decode((string) self::decode($header64), true);
        $claims = json_decode((string) self::decode($payload64), true);
        $signature = self::decode($signature64);

        if (! is_array($header) || ! is_array($claims) || $signature === null || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        if (! hash_equals(hash_hmac('sha256', "{$header64}.{$payload64}", $secret, true), $signature)) {
            return null;
        }

        if (isset($claims['exp']) && is_numeric($claims['exp']) && (int) $claims['exp'] + $leewaySeconds < time()) {
            return null;
        }

        return $claims;
    }

    /** Makes a token: only for tests (and for a script that wants to send a notification as the gateway would). */
    public static function signHs256(array $claims, string $secret): string
    {
        $encode = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $head = $encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $encode(json_encode($claims));

        return "{$head}.{$body}.".$encode(hash_hmac('sha256', "{$head}.{$body}", $secret, true));
    }

    private static function decode(string $part): ?string
    {
        $raw = base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4), true);

        return $raw === false ? null : $raw;
    }
}
