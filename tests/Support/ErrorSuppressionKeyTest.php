<?php

declare(strict_types=1);

namespace Lookout\Tracing\Tests\Support;

use Lookout\Tracing\Support\ErrorSuppressionKey;
use Lookout\Tracing\Support\RemoteConfig;
use PHPUnit\Framework\TestCase;

final class ErrorSuppressionKeyTest extends TestCase
{
    public function test_same_class_and_message_produce_a_stable_32_char_key(): void
    {
        $a = ErrorSuppressionKey::compute('App\\Exceptions\\Boom', 'Something broke');
        $b = ErrorSuppressionKey::compute('App\\Exceptions\\Boom', 'Something broke');

        $this->assertSame($a, $b);
        $this->assertSame(32, strlen($a));
    }

    public function test_key_matches_the_server_recipe_byte_for_byte(): void
    {
        // Cross-checked against App\Support\ErrorSuppressionKey::compute() so SDK + server agree.
        $expected = substr(hash('sha256', 'lkt_supp_v1|app\\exceptions\\boom|user <n> not found'), 0, 32);

        $this->assertSame($expected, ErrorSuppressionKey::compute('App\\Exceptions\\Boom', 'User 4212 not found'));
    }

    public function test_volatile_tokens_collapse_occurrences_to_one_key(): void
    {
        $first = ErrorSuppressionKey::compute(
            'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException',
            'HTTP 404 Not Found: GET /storage/site-logos/01ktb3fn7n0me349kth61ydqy6rvqos.png',
        );
        $second = ErrorSuppressionKey::compute(
            'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException',
            'HTTP 404 Not Found: GET /storage/site-logos/02abckj9zz8x71239aaa00bbb1zzzzz.png',
        );

        $this->assertSame($first, $second);
    }

    public function test_remote_config_extracts_suppression_keys_safely(): void
    {
        $this->assertSame(
            ['abc', 'def'],
            RemoteConfig::suppressedKeys(['suppress' => ['abc', 'def', 'abc', 123, '']]),
        );
        $this->assertSame([], RemoteConfig::suppressedKeys(['signals' => []]));
        $this->assertSame([], RemoteConfig::suppressedKeys(['suppress' => 'nope']));
    }
}
