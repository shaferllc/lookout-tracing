<?php

declare(strict_types=1);

namespace Lookout\Tracing;

/**
 * Common {@code op} values aligned with Sentry/OpenTelemetry-style semantics.
 *
 * @see https://docs.sentry.io/platforms/php/tracing/instrumentation/
 */
final class SpanOperation
{
    public const HTTP_SERVER = 'http.server';

    public const HTTP_CLIENT = 'http.client';

    public const DB = 'db';

    public const DB_QUERY = 'db.query';

    public const CACHE_GET = 'cache.get';

    public const CACHE_SET = 'cache.set';

    public const QUEUE_PUBLISH = 'queue.publish';

    public const QUEUE_PROCESS = 'queue.process';

    public const FUNCTION = 'function';

    public const TASK = 'task';
}
