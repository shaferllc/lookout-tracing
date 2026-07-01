<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Closure;
use Illuminate\Http\Request;
use Lookout\Tracing\Debug\DebugPageRenderer;
use Throwable;

/**
 * Public entrypoint for the Lookout debug page ("Lookout's Ignition").
 *
 * The host app decides WHO may see the rich page for a production error by
 * registering a gate callback — typically in a service provider:
 *
 *     Lookout::showDebugPageUsing(fn (Request $r, Throwable $e) =>
 *         Gate::allows('viewPlatformAdmin') || DebugAllowedIp::allows($r->ip())
 *     );
 *
 * When no callback is registered the page defaults to `config('app.debug')` —
 * i.e. the normal local/dev Ignition experience, and off in production.
 */
final class Lookout
{
    /** @var (Closure(Request, Throwable): bool)|null */
    private static ?Closure $debugPageGate = null;

    /** @var array<string, mixed>|Closure(Request, Throwable): array<string, mixed>|null */
    private static mixed $debugPageMeta = null;

    /**
     * Register the authorization gate for the production debug page.
     *
     * @param  callable(Request, Throwable): bool  $gate
     */
    public static function showDebugPageUsing(callable $gate): void
    {
        self::$debugPageGate = Closure::fromCallable($gate);
    }

    /**
     * Provide extra view data for the page (reference id, "view in Lookout" URL,
     * etc.). Either a static array or a callback resolving one per request.
     *
     * @param  array<string, mixed>|callable(Request, Throwable): array<string, mixed>  $meta
     */
    public static function debugPageMetaUsing(array|callable $meta): void
    {
        self::$debugPageMeta = is_callable($meta) ? Closure::fromCallable($meta) : $meta;
    }

    public static function debugPageEnabled(): bool
    {
        return (bool) config('lookout-tracing.debug_page.enabled', false);
    }

    /**
     * Whether THIS viewer may see the rich page for THIS error. Fails closed:
     * any exception while evaluating the gate → false (branded error instead).
     */
    public static function viewerMaySeeDebugPage(Request $request, Throwable $e): bool
    {
        try {
            if (self::$debugPageGate !== null) {
                return (bool) (self::$debugPageGate)($request, $e);
            }

            return (bool) config('app.debug', false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function resolveDebugPageMeta(Request $request, Throwable $e): array
    {
        try {
            if (self::$debugPageMeta instanceof Closure) {
                $meta = (self::$debugPageMeta)($request, $e);

                return is_array($meta) ? $meta : [];
            }

            return is_array(self::$debugPageMeta) ? self::$debugPageMeta : [];
        } catch (Throwable) {
            return [];
        }
    }

    public static function debugPageRenderer(): DebugPageRenderer
    {
        return app(DebugPageRenderer::class);
    }

    /** Reset all registered hooks (tests). */
    public static function flushDebugPageHooks(): void
    {
        self::$debugPageGate = null;
        self::$debugPageMeta = null;
    }
}
