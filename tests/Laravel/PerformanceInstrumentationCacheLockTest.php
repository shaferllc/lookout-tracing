<?php

declare(strict_types=1);

namespace Lookout\Tracing\Tests\Laravel;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Redis\Events\CommandExecuted;
use Lookout\Tracing\Laravel\PerformanceInstrumentation;
use Lookout\Tracing\Tracer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The server's CacheStampede detector reads a root-span `cache.lock_attempt_count` field. Each
 * lock-related redis command (e.g. `SET ... NX`) carries a per-span `cache.lock_attempt` signal;
 * these tests cover aggregating that signal into the per-transaction count on the root span.
 */
final class PerformanceInstrumentationCacheLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container;
        $app->instance('config', new Repository([
            'lookout-tracing' => [
                'performance' => [
                    'enabled' => true,
                    'collectors' => [
                        'cache' => true,
                        'redis' => true,
                        'http_server' => true,
                    ],
                ],
            ],
        ]));
        Container::setInstance($app);

        Tracer::resetForTesting();
        PerformanceInstrumentation::resetCountersForTesting();
        Tracer::instance()->configure(['performance_enabled' => true]);
        Tracer::instance()->continueTrace(null, null);
        Tracer::instance()->startAutoHttpServerTransaction('GET /test');
    }

    protected function tearDown(): void
    {
        Tracer::resetForTesting();
        PerformanceInstrumentation::resetCountersForTesting();
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * Drive the redis span attribute builder the same way the live Redis::listen callback does,
     * which is where the per-attempt `cache.lock_attempt` signal (and its count) is produced.
     *
     * @param  array<int, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function dispatchRedisCommand(string $command, array $parameters): array
    {
        $connection = new class
        {
            public function getName(): string
            {
                return 'default';
            }
        };

        $event = new CommandExecuted($command, $parameters, 1.0, $connection);

        $method = new ReflectionMethod(PerformanceInstrumentation::class, 'redisSpanAttributes');

        /** @var array<string, mixed> $data */
        $data = $method->invoke(null, $event, 1.0);

        return $data;
    }

    public function test_it_aggregates_lock_attempts_into_a_per_transaction_count(): void
    {
        // Three lock-acquisition attempts (SET ... NX) plus one unrelated command.
        $first = $this->dispatchRedisCommand('set', ['lock:reports', 'token', 'NX']);
        $this->dispatchRedisCommand('set', ['lock:invoices', 'token', 'NX']);
        $this->dispatchRedisCommand('set', ['lock:users', 'token', 'NX']);
        $this->dispatchRedisCommand('get', ['cache:profile']);

        // Each lock-related command carries the per-span signal.
        $this->assertTrue($first['cache.lock_attempt'] ?? false);

        PerformanceInstrumentation::applyTransactionInsightsForTesting();

        $data = Tracer::instance()->getCurrentSpan()->data();

        $this->assertSame(3, $data['cache.lock_attempt_count'] ?? null);
    }

    public function test_it_omits_the_lock_attempt_count_when_there_were_no_lock_attempts(): void
    {
        $data = $this->dispatchRedisCommand('get', ['cache:profile']);
        $this->assertArrayNotHasKey('cache.lock_attempt', $data);

        PerformanceInstrumentation::applyTransactionInsightsForTesting();

        $spanData = Tracer::instance()->getCurrentSpan()->data();

        $this->assertArrayNotHasKey('cache.lock_attempt_count', $spanData);
    }
}
