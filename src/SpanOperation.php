<?php

declare(strict_types=1);

namespace Lookout\Tracing;

/**
 * Common {@code op} values aligned with OpenTelemetry-style semantics.
 */
final class SpanOperation
{
    public const HTTP_SERVER = 'http.server';

    public const HTTP_CLIENT = 'http.client';

    public const DB = 'db';

    public const DB_QUERY = 'db.query';

    public const CACHE_GET = 'cache.get';

    public const CACHE_SET = 'cache.set';

    /** Laravel cache forget / Redis DEL style removals at the cache API layer */
    public const CACHE_REMOVE = 'cache.remove';

    /** Raw Redis commands (Predis/phpredis) via Laravel Redis facade */
    public const REDIS_COMMAND = 'db.redis';

    public const QUEUE_PUBLISH = 'queue.publish';

    public const QUEUE_PROCESS = 'queue.process';

    public const CONSOLE_COMMAND = 'console.command';

    public const VIEW_RENDER = 'view.render';

    public const LOG = 'log';

    public const FUNCTION = 'function';

    public const TASK = 'task';
}
