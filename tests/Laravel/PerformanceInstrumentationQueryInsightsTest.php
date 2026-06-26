<?php

declare(strict_types=1);

namespace Lookout\Tracing\Tests\Laravel;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use Lookout\Tracing\Laravel\PerformanceInstrumentation;
use Lookout\Tracing\Tracer;
use PHPUnit\Framework\TestCase;

/**
 * The server turns root-span DB insight fields into synthetic performance issues but needs to know
 * WHERE a slow / N+1 query came from to render a Stack tab. These tests cover the per-transaction
 * capture of the offending N+1 statement and the slowest statement, each with call frames, on the
 * root span data.
 */
final class PerformanceInstrumentationQueryInsightsTest extends TestCase
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
                        'database' => true,
                        'http_server' => true,
                    ],
                    'database_sample_every' => 1,
                    'slow_query_ms' => 100,
                    'query_insights' => [
                        'enabled' => true,
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

    private function dispatchQuery(string $sql, float $timeMs): void
    {
        $connection = new class
        {
            public function getName(): string
            {
                return 'mysql';
            }
        };

        PerformanceInstrumentation::onQueryExecuted(new QueryExecuted($sql, [1], $timeMs, $connection));
    }

    public function test_it_captures_the_offending_query_and_frames_for_a_suspected_n_plus_one(): void
    {
        // Repeat the offending statement enough to cross the default N+1 thresholds (>=4 repeats, >=8 queries).
        for ($i = 0; $i < 6; $i++) {
            $this->dispatchQuery('select * from comments where post_id = ?', 2.0);
        }
        $this->dispatchQuery('select * from users where id = ?', 1.0);
        $this->dispatchQuery('select * from posts where id = ?', 1.0);

        PerformanceInstrumentation::applyTransactionInsightsForTesting();

        $data = Tracer::instance()->getCurrentSpan()->data();

        $this->assertTrue($data['db.suspected_n_plus_one'] ?? false);
        $this->assertSame('select * from comments where post_id = ?', $data['db.n_plus_one_query'] ?? null);

        $frames = $data['db.n_plus_one_frames'] ?? [];
        $this->assertIsArray($frames);
        $this->assertNotEmpty($frames);
        foreach ($frames as $frame) {
            $this->assertArrayHasKey('function', $frame);
            $this->assertArrayHasKey('call', $frame);
            $this->assertArrayHasKey('file', $frame);
            $this->assertArrayHasKey('line', $frame);
            $this->assertIsInt($frame['line']);
        }
    }

    public function test_it_captures_the_slowest_query_and_frames_when_over_the_threshold(): void
    {
        $this->dispatchQuery('select * from users where id = ?', 5.0);
        $this->dispatchQuery('select * from reports where year = ?', 150.0);
        $this->dispatchQuery('select * from posts where id = ?', 10.0);

        PerformanceInstrumentation::applyTransactionInsightsForTesting();

        $data = Tracer::instance()->getCurrentSpan()->data();

        $this->assertSame('select * from reports where year = ?', $data['db.slow_query'] ?? null);
        $this->assertSame(150.0, $data['db.slow_query_time_ms'] ?? null);

        $frames = $data['db.slow_query_frames'] ?? [];
        $this->assertIsArray($frames);
        $this->assertNotEmpty($frames);
        $this->assertArrayHasKey('call', $frames[0]);
    }

    public function test_it_omits_insight_frames_when_query_insights_are_disabled(): void
    {
        Container::getInstance()
            ->make('config')
            ->set('lookout-tracing.performance.query_insights.enabled', false);

        for ($i = 0; $i < 6; $i++) {
            $this->dispatchQuery('select * from comments where post_id = ?', 200.0);
        }
        $this->dispatchQuery('select * from users where id = ?', 1.0);
        $this->dispatchQuery('select * from posts where id = ?', 1.0);

        PerformanceInstrumentation::applyTransactionInsightsForTesting();

        $data = Tracer::instance()->getCurrentSpan()->data();

        $this->assertArrayNotHasKey('db.n_plus_one_query', $data);
        $this->assertArrayNotHasKey('db.n_plus_one_frames', $data);
        $this->assertArrayNotHasKey('db.slow_query', $data);
        $this->assertArrayNotHasKey('db.slow_query_frames', $data);
    }
}
