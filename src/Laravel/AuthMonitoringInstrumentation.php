<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Lookout\Tracing\Auth\Client as AuthClient;
use Lookout\Tracing\Tracer;
use Throwable;

/**
 * Reports Laravel authentication lifecycle to {@code POST /api/ingest/auth}.
 *
 * Each event (login, logout, failed, lockout, registered, password_reset, verified) is appended
 * server-side as its own row. No credentials or passwords are sent — only the authenticated user's
 * id and a display label (email/name when available).
 */
final class AuthMonitoringInstrumentation
{
    public static function register(Dispatcher $events): void
    {
        if (! self::enabled()) {
            return;
        }

        if (class_exists(Login::class)) {
            $events->listen(Login::class, [self::class, 'onLogin']);
        }
        if (class_exists(Logout::class)) {
            $events->listen(Logout::class, [self::class, 'onLogout']);
        }
        if (class_exists(Failed::class)) {
            $events->listen(Failed::class, [self::class, 'onFailed']);
        }
        if (class_exists(Lockout::class)) {
            $events->listen(Lockout::class, [self::class, 'onLockout']);
        }
        if (class_exists(Registered::class)) {
            $events->listen(Registered::class, [self::class, 'onRegistered']);
        }
        if (class_exists(PasswordReset::class)) {
            $events->listen(PasswordReset::class, [self::class, 'onPasswordReset']);
        }
        if (class_exists(Verified::class)) {
            $events->listen(Verified::class, [self::class, 'onVerified']);
        }
    }

    public static function onLogin(Login $event): void
    {
        self::capture('login', $event->guard ?? null, $event->user, (bool) ($event->remember ?? false));
    }

    public static function onLogout(Logout $event): void
    {
        self::capture('logout', $event->guard ?? null, $event->user, null);
    }

    public static function onFailed(Failed $event): void
    {
        $label = null;
        $credentials = $event->credentials ?? [];
        if (is_array($credentials) && isset($credentials['email']) && is_string($credentials['email'])) {
            $label = $credentials['email'];
        }

        self::capture('failed', $event->guard ?? null, $event->user, null, $label);
    }

    public static function onLockout(Lockout $event): void
    {
        self::capture('lockout', null, null, null);
    }

    public static function onRegistered(Registered $event): void
    {
        self::capture('registered', null, $event->user, null);
    }

    public static function onPasswordReset(PasswordReset $event): void
    {
        self::capture('password_reset', null, $event->user, null);
    }

    public static function onVerified(Verified $event): void
    {
        self::capture('verified', null, $event->user, null);
    }

    private static function capture(string $eventType, ?string $guard, mixed $user, ?bool $remember, ?string $labelOverride = null): void
    {
        if (! self::enabled()) {
            return;
        }

        $userId = null;
        $label = $labelOverride;
        if ($user instanceof Authenticatable) {
            $userId = self::stringOrNull($user->getAuthIdentifier());
            $label ??= self::userLabel($user);
        }

        [$ip, $userAgent] = self::requestContext();

        AuthClient::captureAuthEvent(
            $eventType,
            $guard,
            $userId,
            $label,
            $ip,
            $userAgent,
            $remember,
            null,
            null,
            self::currentTraceId(),
        );
    }

    private static function userLabel(Authenticatable $user): ?string
    {
        foreach (['email', 'name', 'username'] as $attribute) {
            try {
                $value = $user->getAttribute($attribute);
            } catch (Throwable) {
                $value = null;
            }
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function requestContext(): array
    {
        try {
            $request = function_exists('request') ? request() : null;
            if ($request === null) {
                return [null, null];
            }

            $ip = $request->ip();
            $userAgent = $request->userAgent();

            return [
                is_string($ip) ? $ip : null,
                is_string($userAgent) ? substr($userAgent, 0, 512) : null,
            ];
        } catch (Throwable) {
            return [null, null];
        }
    }

    private static function enabled(): bool
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg) || ! empty($cfg['disabled'])) {
            return false;
        }
        $authCfg = is_array($cfg['auth_monitoring'] ?? null) ? $cfg['auth_monitoring'] : [];
        if (empty($authCfg['enabled'])) {
            return false;
        }

        $key = $cfg['api_key'] ?? null;
        $base = $cfg['base_uri'] ?? null;

        return is_string($key) && $key !== '' && is_string($base) && rtrim(trim($base), '/') !== '';
    }

    private static function currentTraceId(): ?string
    {
        $id = Tracer::instance()->traceId();

        return $id !== '' ? $id : null;
    }
}
