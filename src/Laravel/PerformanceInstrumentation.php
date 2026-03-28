<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Events\WritingManyKeys;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Redis;
use Lookout\Tracing\Span;
use Lookout\Tracing\SpanOperation;
use Lookout\Tracing\Support\MemoryPeakReset;
use Lookout\Tracing\Support\SqlFingerprint;
use Lookout\Tracing\TracePropagationHeader;
use Lookout\Tracing\Tracer;
use Lookout\Tracing\TraceWireHeaders;
use Throwable;
use WeakMap;

/**
 * Span collectors on top of Laravel events (queries, logs, HTTP terminate, Artisan, queue).
 */
final class PerformanceInstrumentation
{
    private static int $querySeq = 0;

    private static int $queryTotalCount = 0;

    /** @var array<string, int> */
    private static array $sqlFingerprintCounts = [];

    /** @var list<float> */
    private static array $cacheGetStartStack = [];

    /** @var list<float> */
    private static array $cacheWriteStartStack = [];

    /** @var list<float> */
    private static array $cacheForgetStartStack = [];

    /** @var WeakMap<HttpClientRequest, Span>|null */
    private static ?WeakMap $httpClientSpans = null;

    /**
     * @var list<array{start: float, store: string, total: int, hits: int, misses: int, completed: int}>
     */
    private static array $cacheManyGetPending = [];

    /**
     * @var list<array{start: float, store: string, total: int, outcomes: int, failures: int}>
     */
    private static array $cachePutManyPending = [];

    private static int $cacheInsightHits = 0;

    private static int $cacheInsightMisses = 0;

    private static int $cacheInsightSets = 0;

    private static int $cacheInsightForgets = 0;

    private static int $cacheManyGetBatches = 0;

    private static int $cacheManyPutBatches = 0;

    public static function register(Dispatcher $events): void
    {
        $perf = config('lookout-tracing.performance');
        if (! is_array($perf) || empty($perf['enabled'])) {
            return;
        }

        $collectors = is_array($perf['collectors'] ?? null) ? $perf['collectors'] : [];

        if (! empty($collectors['http_server'])) {
            $events->listen(RequestHandled::class, [self::class, 'onRequestHandled']);
        }

        if (! empty($collectors['console'])) {
            $events->listen(CommandStarting::class, [self::class, 'onCommandStarting']);
            $events->listen(CommandFinished::class, [self::class, 'onCommandFinished']);
        }

        if (! empty($collectors['queue'])) {
            $events->listen(JobProcessing::class, [self::class, 'onJobProcessing']);
            $events->listen(JobAttempted::class, [self::class, 'onJobAttempted']);
        }

        if (! empty($collectors['database'])) {
            $events->listen(QueryExecuted::class, [self::class, 'onQueryExecuted']);
        }

        if (! empty($collectors['log'])) {
            $events->listen(MessageLogged::class, [self::class, 'onMessageLogged']);
        }

        if (! empty($collectors['cache'])) {
            self::registerCachePerformance($events);
        }

        if (! empty($collectors['http_client'])) {
            self::registerLaravelHttpClientPerformance($events);
        }
    }

    /**
     * Reset per-request DB / cache timing stacks before a new HTTP transaction starts.
     */
    public static function resetHttpRequestCounters(): void
    {
        self::$querySeq = 0;
        self::$queryTotalCount = 0;
        self::$sqlFingerprintCounts = [];
        self::$cacheGetStartStack = [];
        self::$cacheWriteStartStack = [];
        self::$cacheForgetStartStack = [];
        self::$cacheManyGetPending = [];
        self::$cachePutManyPending = [];
        self::$cacheInsightHits = 0;
        self::$cacheInsightMisses = 0;
        self::$cacheInsightSets = 0;
        self::$cacheInsightForgets = 0;
        self::$cacheManyGetBatches = 0;
        self::$cacheManyPutBatches = 0;
    }

    public static function registerRedisPerformanceListener(): void
    {
        $perf = config('lookout-tracing.performance');
        if (! is_array($perf) || empty($perf['enabled'])) {
            return;
        }
        $collectors = is_array($perf['collectors'] ?? null) ? $perf['collectors'] : [];
        if (empty($collectors['redis'])) {
            return;
        }
        if (! class_exists(Redis::class)) {
            return;
        }
        try {
            Redis::listen(function (CommandExecuted $e): void {
                if (! self::performanceEnabled() || ! self::collector('redis')) {
                    return;
                }
                $parent = Tracer::instance()->getCurrentSpan();
                if ($parent === null || $parent->isFinished() || ! Tracer::instance()->isSpanRecordingEnabled()) {
                    return;
                }

                $timeMs = (float) $e->time;
                $now = microtime(true);
                $start = $now - ($timeMs / 1000.0);

                $desc = self::redisCommandDescription($e);
                $child = $parent->startChild(SpanOperation::REDIS_COMMAND, $desc, $start);
                $child->setData(self::redisSpanAttributes($e, $timeMs));
                $child->finish($now);
            });
        } catch (Throwable) {
            // Redis not configured
        }
    }

    private static function registerCachePerformance(Dispatcher $events): void
    {
        if (! class_exists(RetrievingKey::class)) {
            return;
        }

        $events->listen(RetrievingManyKeys::class, function (RetrievingManyKeys $e): void {
            if (! self::performanceEnabled() || ! self::collector('cache')) {
                return;
            }
            $keys = is_array($e->keys) ? $e->keys : [];
            $total = count($keys);
            if ($total === 0) {
                return;
            }
            self::$cacheManyGetPending[] = [
                'start' => microtime(true),
                'store' => (string) ($e->storeName ?? ''),
                'total' => $total,
                'hits' => 0,
                'misses' => 0,
                'completed' => 0,
            ];
        });

        $events->listen(RetrievingKey::class, function (RetrievingKey $e): void {
            if (! self::performanceEnabled() || ! self::collector('cache')) {
                return;
            }
            self::$cacheGetStartStack[] = microtime(true);
        });

        $events->listen(CacheHit::class, function (CacheHit $e): void {
            if (! self::performanceEnabled() || ! self::collector('cache')) {
                return;
            }
            if (self::consumeCacheManyGetHitOrMiss(true)) {
                return;
            }
            self::$cacheInsightHits++;
            $start = array_pop(self::$cacheGetStartStack);
            self::finishCacheSpan(
                SpanOperation::CACHE_GET,
                'hit '.self::truncateCacheKey((string) $e->key),
                is_float($start) ? $start : microtime(true),
                self::cacheSpanDataBase($e, [
                    'cache.result' => 'hit',
                ])
            );
        });

        $events->listen(CacheMissed::class, function (CacheMissed $e): void {
            if (! self::performanceEnabled() || ! self::collector('cache')) {
                return;
            }
            if (self::consumeCacheManyGetHitOrMiss(false)) {
                return;
            }
            self::$cacheInsightMisses++;
            $start = array_pop(self::$cacheGetStartStack);
            self::finishCacheSpan(
                SpanOperation::CACHE_GET,
                'miss '.self::truncateCacheKey((string) $e->key),
                is_float($start) ? $start : microtime(true),
                self::cacheSpanDataBase($e, [
                    'cache.result' => 'miss',
                ])
            );
        });

        $events->listen(WritingManyKeys::class, function (WritingManyKeys $e): void {
            if (! self::performanceEnabled() || ! self::collector('cache')) {
                return;
            }
            $keys = is_array($e->keys) ? $e->keys : [];
            $total = count($keys);
            if ($total === 0) {
                return;
            }
            self::$cachePutManyPending[] = [
                'start' => microtime(true),
                'store' => (string) ($e->storeName ?? ''),
                'total' => $total,
                'outcomes' => 0,
                'failures' => 0,
            ];
        });

        $events->listen(WritingKey::class, function (WritingKey $e): void {
            if (! self::performanceEnabled() || ! self::collector('cache')) {
                return;
            }
            self::$cacheWriteStartStack[] = microtime(true);
        });

        $events->listen(KeyWritten::class, function (KeyWritten $e): void {
            if (! self::performanceEnabled() || ! self::collector('cache')) {
                return;
            }
            if (self::consumeCachePutManyOutcome(false)) {
                return;
            }
            self::$cacheInsightSets++;
            $start = array_pop(self::$cacheWriteStartStack);
            self::finishCacheSpan(
                SpanOperation::CACHE_SET,
                'set '.self::truncateCacheKey((string) $e->key),
                is_float($start) ? $start : microtime(true),
                self::cacheSpanDataBase($e, [
                    'cache.result' => 'ok',
                ])
            );
        });

        $events->listen(KeyWriteFailed::class, function (KeyWriteFailed $e): void {
            if (! self::performanceEnabled() || ! self::collector('cache')) {
                return;
            }
            if (self::consumeCachePutManyOutcome(true)) {
                return;
            }
            self::$cacheInsightSets++;
            $start = array_pop(self::$cacheWriteStartStack);
            self::finishCacheSpan(
                SpanOperation::CACHE_SET,
                'set failed '.self::truncateCacheKey((string) $e->key),
                is_float($start) ? $start : microtime(true),
                self::cacheSpanDataBase($e, []),
                'internal_error'
            );
        });

        $events->listen(ForgettingKey::class, function (ForgettingKey $e): void {
            if (! self::performanceEnabled() || ! self::collector('cache')) {
                return;
            }
            self::$cacheForgetStartStack[] = microtime(true);
        });

        $events->listen(KeyForgotten::class, function (KeyForgotten $e): void {
            if (! self::performanceEnabled() || ! self::collector('cache')) {
                return;
            }
            self::$cacheInsightForgets++;
            $start = array_pop(self::$cacheForgetStartStack);
            self::finishCacheSpan(
                SpanOperation::CACHE_REMOVE,
                'forget '.self::truncateCacheKey((string) $e->key),
                is_float($start) ? $start : microtime(true),
                self::cacheSpanDataBase($e, [
                    'cache.result' => 'ok',
                ])
            );
        });

        $events->listen(KeyForgetFailed::class, function (KeyForgetFailed $e): void {
            if (! self::performanceEnabled() || ! self::collector('cache')) {
                return;
            }
            self::$cacheInsightForgets++;
            $start = array_pop(self::$cacheForgetStartStack);
            self::finishCacheSpan(
                SpanOperation::CACHE_REMOVE,
                'forget failed '.self::truncateCacheKey((string) $e->key),
                is_float($start) ? $start : microtime(true),
                self::cacheSpanDataBase($e, []),
                'internal_error'
            );
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function finishCacheSpan(string $op, string $description, float $start, array $data, ?string $status = null): void
    {
        $parent = Tracer::instance()->getCurrentSpan();
        if ($parent === null || $parent->isFinished() || ! Tracer::instance()->isSpanRecordingEnabled()) {
            return;
        }
        $now = microtime(true);
        $data['cache.duration_ms'] = max(0.0, round(($now - $start) * 1000, 3));
        $child = $parent->startChild($op, $description, $start);
        $child->setData($data);
        if ($status !== null) {
            $child->setStatus($status);
        }
        $child->finish($now);
    }

    /**
     * @param  array{start: float, store: string, total: int, hits: int, misses: int, completed: int}  $batch
     */
    private static function finishCacheManyGetBatchSpan(array $batch): void
    {
        self::$cacheManyGetBatches++;
        $desc = 'many get '.$batch['total'].' keys ('.$batch['hits'].' hit, '.$batch['misses'].' miss)';
        self::finishCacheSpan(
            SpanOperation::CACHE_GET,
            $desc,
            $batch['start'],
            [
                'cache.operation' => 'many_get',
                'cache.batch.keys' => $batch['total'],
                'cache.batch.hits' => $batch['hits'],
                'cache.batch.misses' => $batch['misses'],
                'cache.store' => $batch['store'],
            ]
        );
    }

    /**
     * @param  array{start: float, store: string, total: int, outcomes: int, failures: int}  $batch
     */
    private static function finishCachePutManyBatchSpan(array $batch): void
    {
        self::$cacheManyPutBatches++;
        $desc = 'many set '.$batch['total'].' keys';
        $status = $batch['failures'] > 0 ? 'internal_error' : null;
        self::finishCacheSpan(
            SpanOperation::CACHE_SET,
            $desc,
            $batch['start'],
            [
                'cache.operation' => 'many_set',
                'cache.batch.keys' => $batch['total'],
                'cache.batch.failures' => $batch['failures'],
                'cache.store' => $batch['store'],
            ],
            $status
        );
    }

    private static function consumeCacheManyGetHitOrMiss(bool $isHit): bool
    {
        if (self::$cacheManyGetPending === []) {
            return false;
        }
        $k = array_key_last(self::$cacheManyGetPending);
        if ($isHit) {
            self::$cacheManyGetPending[$k]['hits']++;
            self::$cacheInsightHits++;
        } else {
            self::$cacheManyGetPending[$k]['misses']++;
            self::$cacheInsightMisses++;
        }
        self::$cacheManyGetPending[$k]['completed']++;
        $completed = self::$cacheManyGetPending[$k]['completed'];
        $total = self::$cacheManyGetPending[$k]['total'];
        if ($completed >= $total) {
            $done = array_pop(self::$cacheManyGetPending);
            self::finishCacheManyGetBatchSpan($done);
        }

        return true;
    }

    private static function consumeCachePutManyOutcome(bool $failed): bool
    {
        if (self::$cachePutManyPending === []) {
            return false;
        }
        $k = array_key_last(self::$cachePutManyPending);
        self::$cachePutManyPending[$k]['outcomes']++;
        if ($failed) {
            self::$cachePutManyPending[$k]['failures']++;
        }
        self::$cacheInsightSets++;
        $outcomes = self::$cachePutManyPending[$k]['outcomes'];
        $total = self::$cachePutManyPending[$k]['total'];
        if ($outcomes >= $total) {
            $done = array_pop(self::$cachePutManyPending);
            self::finishCachePutManyBatchSpan($done);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function cacheSpanDataBase(CacheEvent $e, array $extra): array
    {
        $data = array_merge([
            'cache.key' => self::truncateCacheKey((string) $e->key),
            'cache.store' => (string) ($e->storeName ?? ''),
        ], $extra);
        $tags = $e->tags ?? [];
        if (is_array($tags) && $tags !== []) {
            $data['cache.tags_count'] = count($tags);
        }
        if ($e instanceof KeyWritten && isset($e->seconds) && is_int($e->seconds) && $e->seconds > 0) {
            $data['cache.ttl_seconds'] = $e->seconds;
        }

        return $data;
    }

    private static function truncateCacheKey(string $key): string
    {
        return strlen($key) > 200 ? substr($key, 0, 197).'…' : $key;
    }

    /**
     * @return array<string, mixed>
     */
    private static function redisSpanAttributes(CommandExecuted $e, float $timeMs): array
    {
        $cmd = strtoupper((string) $e->command);
        $params = $e->parameters;
        $hay = self::redisCommandSearchHaystack($cmd, $params);
        $info = self::redisBlockingAndLockInfo($cmd, $params, $hay);

        $data = [
            'db.system' => 'redis',
            'db.redis.connection' => $e->connectionName,
            'db.duration_ms' => round($timeMs, 3),
        ];

        if ($info['blocking']) {
            $data['redis.blocking'] = true;
            if ($timeMs > 0) {
                $data['redis.blocking_duration_ms'] = round($timeMs, 3);
            }
        }
        if ($info['lock_related']) {
            $data['cache.lock_attempt'] = true;
        }

        return $data;
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    private static function redisCommandSearchHaystack(string $command, array $parameters): string
    {
        $parts = [strtolower($command)];
        foreach ($parameters as $p) {
            if (is_string($p)) {
                $parts[] = strtolower($p);
            } elseif (is_scalar($p) || $p === null) {
                $parts[] = strtolower((string) json_encode($p));
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<int, mixed>  $parameters
     * @return array{blocking: bool, lock_related: bool}
     */
    private static function redisBlockingAndLockInfo(string $command, array $parameters, string $hay): array
    {
        $cmd = strtolower($command);
        $blocking = false;
        $lockRelated = false;

        static $nativeBlocking = [
            'blpop', 'brpop', 'brpoplpush', 'blmove', 'bzpopmin', 'bzpopmax',
        ];
        foreach ($nativeBlocking as $prefix) {
            if ($cmd === $prefix || str_starts_with($cmd, $prefix)) {
                $blocking = true;
                break;
            }
        }

        if (($cmd === 'xread' || $cmd === 'xreadgroup') && preg_match('/\bblock\s+\d+/i', $hay)) {
            $blocking = true;
        }

        if ($cmd === 'set') {
            foreach ($parameters as $p) {
                if (is_string($p) && strtoupper($p) === 'NX') {
                    $lockRelated = true;
                    break;
                }
            }
        }

        return ['blocking' => $blocking, 'lock_related' => $lockRelated];
    }

    private static function redisCommandDescription(CommandExecuted $e): string
    {
        $cmd = strtoupper((string) $e->command);
        $parts = [$cmd];
        foreach (array_slice($e->parameters, 0, 4) as $p) {
            if (is_string($p)) {
                $parts[] = self::truncateCacheKey($p);
            } elseif (is_scalar($p) || $p === null) {
                $parts[] = (string) json_encode($p);
            } else {
                $parts[] = '{…}';
            }
        }
        $desc = implode(' ', $parts);

        return strlen($desc) > 512 ? substr($desc, 0, 509).'…' : $desc;
    }

    private static function registerLaravelHttpClientPerformance(Dispatcher $events): void
    {
        if (! class_exists(RequestSending::class)) {
            return;
        }

        $events->listen(RequestSending::class, [self::class, 'onHttpClientRequestSending']);
        $events->listen(ResponseReceived::class, [self::class, 'onHttpClientResponseReceived']);
        $events->listen(ConnectionFailed::class, [self::class, 'onHttpClientConnectionFailed']);
    }

    public static function onHttpClientRequestSending(RequestSending $event): void
    {
        if (! self::performanceEnabled() || ! self::collector('http_client')) {
            return;
        }
        if (! Tracer::instance()->shouldRecordHttpClientSpans()) {
            return;
        }
        $parent = Tracer::instance()->getCurrentSpan();
        if ($parent === null || $parent->isFinished() || ! Tracer::instance()->isSpanRecordingEnabled()) {
            return;
        }

        $req = $event->request;
        if (! $req instanceof HttpClientRequest) {
            return;
        }

        $uri = method_exists($req, 'url') ? (string) $req->url() : '';
        $method = method_exists($req, 'method') ? (string) $req->method() : 'GET';
        $desc = $method.' '.$uri;
        if (strlen($desc) > 512) {
            $desc = substr($desc, 0, 509).'…';
        }
        $span = $parent->startChild(SpanOperation::HTTP_CLIENT, $desc);
        $host = parse_url($uri, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $span->setData(['server.address' => $host]);
        }
        self::httpClientSpanMap()->offsetSet($req, $span);
    }

    public static function onHttpClientResponseReceived(ResponseReceived $event): void
    {
        if (! self::performanceEnabled() || ! self::collector('http_client')) {
            return;
        }
        $req = $event->request;
        if (! $req instanceof HttpClientRequest) {
            return;
        }
        $span = self::httpClientSpanMap()->offsetExists($req) ? self::httpClientSpanMap()->offsetGet($req) : null;
        if ($span === null || $span->isFinished()) {
            return;
        }
        self::httpClientSpanMap()->offsetUnset($req);
        $res = $event->response;
        $code = method_exists($res, 'status') ? (int) $res->status() : 0;
        if ($code > 0) {
            $span->setData(['http.status_code' => $code]);
        }
        if ($code >= 500) {
            $span->setStatus('internal_error');
        }
        $span->finish();
    }

    public static function onHttpClientConnectionFailed(ConnectionFailed $event): void
    {
        if (! self::performanceEnabled() || ! self::collector('http_client')) {
            return;
        }
        $req = $event->request;
        if (! $req instanceof HttpClientRequest) {
            return;
        }
        $span = self::httpClientSpanMap()->offsetExists($req) ? self::httpClientSpanMap()->offsetGet($req) : null;
        if ($span === null || $span->isFinished()) {
            return;
        }
        self::httpClientSpanMap()->offsetUnset($req);
        $span->setStatus('internal_error');
        $span->setData(['error' => $event->exception->getMessage()]);
        $span->finish();
    }

    /**
     * @return WeakMap<HttpClientRequest, Span>
     */
    private static function httpClientSpanMap(): WeakMap
    {
        return self::$httpClientSpans ??= new WeakMap;
    }

    public static function onRequestHandled(RequestHandled $event): void
    {
        if (! self::performanceEnabled()) {
            return;
        }
        self::attachTransactionInsights();
        Tracer::instance()->finishAutoHttpServerTransaction($event->response->getStatusCode());
    }

    public static function onCommandStarting(CommandStarting $event): void
    {
        if (! self::performanceEnabled() || ! self::collector('console')) {
            return;
        }
        self::resetTransactionCounters();
        MemoryPeakReset::beforeUnitOfWork();
        Tracer::instance()->continueTrace(null, null);
        $name = 'artisan '.$event->command;
        Tracer::instance()->startAutoConsoleTransaction($name);
    }

    public static function onCommandFinished(CommandFinished $event): void
    {
        if (! self::performanceEnabled() || ! self::collector('console')) {
            return;
        }
        self::attachTransactionInsights();
        $span = Tracer::instance()->getCurrentSpan();
        if ($span !== null && ! $span->isFinished()) {
            $span->setData(['exit_code' => $event->exitCode]);
        }
        Tracer::instance()->finishAutoConsoleTransaction();
        self::maybeFlush();
    }

    public static function onJobProcessing(JobProcessing $event): void
    {
        if (! self::performanceEnabled() || ! self::collector('queue')) {
            return;
        }
        self::resetTransactionCounters();
        MemoryPeakReset::beforeUnitOfWork();

        $tracer = Tracer::instance();
        $tracer->suspendBeforeQueueJobDispatch();

        $payload = self::jobPayload($event->job);
        $propagated = self::extractLookoutQueueTrace($payload);
        if ($propagated['trace_propagation'] !== null && $propagated['trace_propagation'] !== '') {
            $tracer->continueTrace($propagated['trace_propagation'], $propagated['baggage'], true);
        } else {
            $tracer->continueTrace(null, null, true);
        }

        $name = self::resolveJobName($event->job);
        $tracer->startAutoQueueTransaction($name);
        $span = $tracer->getCurrentSpan();
        if ($span !== null) {
            $maxTries = $payload['maxTries'] ?? null;
            $uuid = $payload['uuid'] ?? null;
            $queueName = '';
            if ($event->job instanceof QueueJobContract && method_exists($event->job, 'getQueue')) {
                $queueName = (string) $event->job->getQueue();
            }
            $attempt = 1;
            if ($event->job instanceof QueueJobContract && method_exists($event->job, 'attempts')) {
                $attempt = (int) $event->job->attempts();
            }
            $data = [
                'queue.connection' => $event->connectionName,
                'queue.name' => $queueName,
                'queue.attempt' => $attempt,
                'job.display_name' => substr($name, 0, 512),
            ];
            if ($maxTries !== null) {
                $data['queue.max_tries'] = $maxTries;
            }
            if (is_string($uuid) && $uuid !== '') {
                $data['job.uuid'] = $uuid;
            }
            $span->setData($data);
        }
    }

    /**
     * Finishes the {@code queue.process} transaction once per attempt (success, failure, or release for retry).
     */
    public static function onJobAttempted(JobAttempted $event): void
    {
        if (! self::performanceEnabled() || ! self::collector('queue')) {
            return;
        }
        self::attachTransactionInsights();
        $tracer = Tracer::instance();
        $span = $tracer->getCurrentSpan();
        if ($span !== null && ! $span->isFinished() && $span->op === SpanOperation::QUEUE_PROCESS) {
            if (! $event->successful()) {
                $span->setStatus('internal_error');
                $ex = $event->exception;
                if ($ex instanceof Throwable) {
                    $msg = $ex->getMessage();
                    if (strlen($msg) > 500) {
                        $msg = substr($msg, 0, 497).'…';
                    }
                    $span->setData([
                        'error' => $msg,
                        'exception.class' => $ex::class,
                    ]);
                }
            }
            if ($event->job->hasFailed()) {
                $span->setData(['queue.failed_permanently' => true]);
            }
        }
        $tracer->finishAutoQueueTransaction();
        $tracer->restoreAfterQueueJobAttempt();
        self::maybeFlush();
    }

    /**
     * Merged into every queue payload so workers can {@see Tracer::continueTrace()} under the dispatching request
     * (and optional {@code queue.publish} producer span).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function mergeQueuePayloadTraceContext(string $connection, ?string $queue, array $payload): array
    {
        if (! self::performanceEnabled() || ! self::collector('queue')) {
            return [];
        }
        if (! filter_var(config('lookout-tracing.performance.queue_propagate_trace', true), FILTER_VALIDATE_BOOLEAN)) {
            return [];
        }

        $tracer = Tracer::instance();
        $parent = $tracer->getCurrentSpan();
        if ($parent === null || $parent->isFinished()) {
            return [];
        }

        $displayName = 'queue.job';
        if (isset($payload['displayName']) && is_string($payload['displayName']) && $payload['displayName'] !== '') {
            $displayName = $payload['displayName'];
        } elseif (isset($payload['job']) && is_string($payload['job']) && $payload['job'] !== '') {
            $displayName = $payload['job'];
        }

        $parsed = TracePropagationHeader::parse($tracer->traceparent());
        if ($parsed === null) {
            return [];
        }

        $recordPublish = filter_var(config('lookout-tracing.performance.queue_publish_span', true), FILTER_VALIDATE_BOOLEAN)
            && $tracer->isSpanRecordingEnabled();

        if ($recordPublish) {
            $desc = strlen($displayName) > 512 ? substr($displayName, 0, 509).'…' : $displayName;
            $pub = $parent->startChild(SpanOperation::QUEUE_PUBLISH, $desc);
            $pubData = [
                'queue.connection' => $connection,
                'queue.name' => (string) ($queue ?? ''),
                'job.display_name' => substr($displayName, 0, 512),
            ];
            if (isset($payload['uuid']) && is_string($payload['uuid']) && $payload['uuid'] !== '') {
                $pubData['job.uuid'] = $payload['uuid'];
            }
            $pub->setData($pubData);
            $pub->finish();
            $tracePropagation = TracePropagationHeader::format($parsed['trace_id'], $pub->spanId, $parsed['sampled']);
        } else {
            $tracePropagation = $tracer->traceparent();
        }

        $alias = TraceWireHeaders::INGEST_TRACE_ALIAS;

        return [
            'lookout_tracing' => [
                $alias => $tracePropagation,
                'baggage' => $tracer->baggageHeader(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{trace_propagation: ?string, baggage: ?string}
     */
    private static function extractLookoutQueueTrace(array $payload): array
    {
        $block = $payload['lookout_tracing'] ?? null;
        if (! is_array($block)) {
            return ['trace_propagation' => null, 'baggage' => null];
        }
        $alias = TraceWireHeaders::INGEST_TRACE_ALIAS;
        $st = $block[$alias] ?? null;
        $bg = $block['baggage'] ?? null;
        $tracePropagation = is_string($st) && trim($st) !== '' ? trim($st) : null;
        $baggage = is_string($bg) ? $bg : null;

        return ['trace_propagation' => $tracePropagation, 'baggage' => $baggage];
    }

    /**
     * @return array<string, mixed>
     */
    private static function jobPayload(mixed $job): array
    {
        if (! is_object($job) || ! method_exists($job, 'payload')) {
            return [];
        }
        try {
            $p = $job->payload();

            return is_array($p) ? $p : [];
        } catch (Throwable) {
            return [];
        }
    }

    public static function onQueryExecuted(QueryExecuted $event): void
    {
        if (! self::performanceEnabled() || ! self::collector('database')) {
            return;
        }

        self::$queryTotalCount++;
        $fp = SqlFingerprint::normalize($event->sql);
        if ($fp !== '') {
            self::$sqlFingerprintCounts[$fp] = (self::$sqlFingerprintCounts[$fp] ?? 0) + 1;
        }

        $parent = Tracer::instance()->getCurrentSpan();
        if ($parent === null || ! Tracer::instance()->isSpanRecordingEnabled()) {
            return;
        }

        self::$querySeq++;
        $sample = (int) (config('lookout-tracing.performance.database_sample_every') ?? 1);
        $sample = max(1, $sample);
        if ((self::$querySeq % $sample) !== 0) {
            return;
        }

        $now = microtime(true);
        $start = $now - ($event->time / 1000.0);
        $sql = strlen($event->sql) > 2000 ? substr($event->sql, 0, 2000).'…' : $event->sql;
        $child = $parent->startChild(SpanOperation::DB_QUERY, $sql, $start);
        $child->setData([
            'db.system' => $event->connectionName,
            'db.duration_ms' => $event->time,
        ]);
        $child->finish($now);
    }

    public static function onMessageLogged(MessageLogged $event): void
    {
        if (! self::performanceEnabled() || ! self::collector('log')) {
            return;
        }
        $span = Tracer::instance()->getCurrentSpan();
        if ($span === null || $span->isFinished() || ! Tracer::instance()->isSpanRecordingEnabled()) {
            return;
        }
        $msg = strlen($event->message) > 500 ? substr($event->message, 0, 500).'…' : $event->message;
        $span->addSpanEvent('log', null, [
            'log.level' => $event->level,
            'log.message' => $msg,
        ]);
    }

    private static function resetTransactionCounters(): void
    {
        self::resetHttpRequestCounters();
    }

    private static function attachTransactionInsights(): void
    {
        $perf = config('lookout-tracing.performance');
        $insights = is_array($perf) ? ($perf['query_insights'] ?? []) : [];
        $enabled = ! is_array($insights) || ($insights['enabled'] ?? true);

        $span = Tracer::instance()->getCurrentSpan();
        if ($span === null || $span->isFinished()) {
            return;
        }

        $data = [];

        if (self::collector('database')) {
            $data['db.query_count'] = self::$queryTotalCount;
        }

        if ($enabled && self::collector('database') && self::$queryTotalCount > 0) {
            $counts = array_values(self::$sqlFingerprintCounts);
            $maxRepeat = $counts !== [] ? max($counts) : 0;
            $unique = count(self::$sqlFingerprintCounts);
            $data['db.unique_statements'] = $unique;
            $data['db.repeat_query_max'] = $maxRepeat;

            $thresholdRepeat = (int) ($insights['n_plus_one_min_repeat'] ?? 4);
            $thresholdRepeat = max(2, $thresholdRepeat);
            $thresholdQueries = (int) ($insights['n_plus_one_min_queries'] ?? 8);
            $thresholdQueries = max(4, $thresholdQueries);
            if ($maxRepeat >= $thresholdRepeat && self::$queryTotalCount >= $thresholdQueries) {
                $data['db.suspected_n_plus_one'] = true;
            }
        }

        if (self::collector('cache')) {
            $cacheTouches = self::$cacheInsightHits + self::$cacheInsightMisses + self::$cacheInsightSets + self::$cacheInsightForgets;
            if ($cacheTouches > 0 || self::$cacheManyGetBatches > 0 || self::$cacheManyPutBatches > 0) {
                $data['cache.hit_count'] = self::$cacheInsightHits;
                $data['cache.miss_count'] = self::$cacheInsightMisses;
                $data['cache.set_count'] = self::$cacheInsightSets;
                $data['cache.forget_count'] = self::$cacheInsightForgets;
                if (self::$cacheManyGetBatches > 0) {
                    $data['cache.batch.many_get'] = self::$cacheManyGetBatches;
                }
                if (self::$cacheManyPutBatches > 0) {
                    $data['cache.batch.many_set'] = self::$cacheManyPutBatches;
                }
                $looked = self::$cacheInsightHits + self::$cacheInsightMisses;
                if ($looked > 0) {
                    $data['cache.hit_ratio'] = round(self::$cacheInsightHits / $looked, 4);
                }
            }
        }

        if (in_array($span->op, [SpanOperation::HTTP_SERVER, SpanOperation::CONSOLE_COMMAND, SpanOperation::QUEUE_PROCESS], true)) {
            $data['php.memory_peak_bytes'] = memory_get_peak_usage(true);
            $data['php.memory_usage_bytes'] = memory_get_usage(true);
        }

        if ($data !== []) {
            $span->setData($data);
        }
    }

    private static function resolveJobName(mixed $job): string
    {
        if ($job instanceof QueueJobContract && method_exists($job, 'resolveName')) {
            return (string) $job->resolveName();
        }

        return is_object($job) ? $job::class : 'queue.job';
    }

    private static function performanceEnabled(): bool
    {
        return Tracer::instance()->isPerformanceEnabled();
    }

    private static function collector(string $key): bool
    {
        $perf = config('lookout-tracing.performance');
        $c = is_array($perf['collectors'] ?? null) ? $perf['collectors'] : [];

        return ! empty($c[$key]);
    }

    private static function maybeFlush(): void
    {
        if (config('lookout-tracing.performance.flush_after_cli_and_queue', false)) {
            TraceIngestFlushReporter::flushWithReporting();
        }
    }
}
