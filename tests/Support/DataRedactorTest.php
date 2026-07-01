<?php

declare(strict_types=1);

namespace Lookout\Tracing\Tests\Support;

use Lookout\Tracing\Support\DataRedactor;
use PHPUnit\Framework\TestCase;

final class DataRedactorTest extends TestCase
{
    protected function tearDown(): void
    {
        DataRedactor::reset();
        parent::tearDown();
    }

    public function test_masks_exact_default_keys_case_insensitively(): void
    {
        $out = DataRedactor::redact(['Password' => 'hunter2', 'Authorization' => 'Bearer x', 'name' => 'Ada']);

        $this->assertSame(DataRedactor::MASK, $out['Password']);
        $this->assertSame(DataRedactor::MASK, $out['Authorization']);
        $this->assertSame('Ada', $out['name']);
    }

    public function test_masks_substring_patterns_missed_by_exact_matching(): void
    {
        $out = DataRedactor::redact([
            'app_key' => 'base64:…',
            'db_password' => 'pw',
            'client_secret' => 'cs',
            'stripe_secret_key' => 'sk_live',
            'x-api-key' => 'k',
            'x_auth_token' => 't',
            'harmless' => 'ok',
        ]);

        foreach (['app_key', 'db_password', 'client_secret', 'stripe_secret_key', 'x-api-key', 'x_auth_token'] as $k) {
            $this->assertSame(DataRedactor::MASK, $out[$k], "$k should be redacted");
        }
        $this->assertSame('ok', $out['harmless']);
    }

    public function test_does_not_over_redact_lookalike_keys(): void
    {
        $out = DataRedactor::redact(['author' => 'Ada', 'keyboard' => 'qwerty', 'monkey' => 'george']);

        $this->assertSame('Ada', $out['author']);
        $this->assertSame('qwerty', $out['keyboard']);
        $this->assertSame('george', $out['monkey']);
    }

    public function test_redacts_recursively_including_list_arrays(): void
    {
        $out = DataRedactor::redact([
            'user' => ['name' => 'Ada', 'password' => 'x'],
            'args' => [['db_password' => 'x'], 'plain'],
        ]);

        $this->assertSame('Ada', $out['user']['name']);
        $this->assertSame(DataRedactor::MASK, $out['user']['password']);
        $this->assertSame(DataRedactor::MASK, $out['args'][0]['db_password']);
        $this->assertSame('plain', $out['args'][1]);
    }

    public function test_configure_adds_host_keys_and_patterns(): void
    {
        DataRedactor::configure(['pin'], ['internal_']);

        $out = DataRedactor::redact(['pin' => '1234', 'internal_note' => 'secret-ish', 'note' => 'fine']);

        $this->assertSame(DataRedactor::MASK, $out['pin']);
        $this->assertSame(DataRedactor::MASK, $out['internal_note']);
        $this->assertSame('fine', $out['note']);
    }

    public function test_scrub_sql_masks_string_and_long_numeric_literals(): void
    {
        $sql = "select * from users where email = 'ada@example.com' and card = 4111111111111111 and id = 42";
        $scrubbed = DataRedactor::scrubSql($sql);

        $this->assertStringNotContainsString('ada@example.com', $scrubbed);
        $this->assertStringNotContainsString('4111111111111111', $scrubbed);
        // Short ids are left intact — they're not secrets and aid debugging.
        $this->assertStringContainsString('id = 42', $scrubbed);
    }

    public function test_scrub_sql_respects_disabled_flag(): void
    {
        DataRedactor::configure([], [], false);
        $sql = "select * from users where email = 'ada@example.com'";

        $this->assertSame($sql, DataRedactor::scrubSql($sql));
    }
}
