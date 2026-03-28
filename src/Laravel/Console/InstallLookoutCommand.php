<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Lookout\Tracing\Support\LookoutDsn;

use function Laravel\Prompts\text;

/**
 * Appends {@code LOOKOUT_DSN} (and optional {@code LOOKOUT_LARAVEL}) to the application {@code .env} file.
 */
final class InstallLookoutCommand extends Command
{
    protected $signature = 'lookout:install
                            {--dsn= : Full DSN, e.g. https://PROJECT_API_KEY@your-lookout.example.com}
                            {--no-quick : Do not append LOOKOUT_LARAVEL=true}';

    protected $description = 'Write Lookout DSN (and optional Laravel quick-start flags) to your .env file';

    public function handle(): int
    {
        $envPath = base_path('.env');
        if (! is_file($envPath)) {
            $this->components->error('No .env file found. Copy .env.example to .env first.');

            return self::FAILURE;
        }

        $dsn = (string) ($this->option('dsn') ?? '');
        if ($dsn === '') {
            if (! $this->input->isInteractive()) {
                $this->components->error('Pass --dsn=https://PROJECT_KEY@lookout-host.example.com when running non-interactively.');

                return self::FAILURE;
            }
            $dsn = text(
                label: 'Lookout DSN (https://YOUR_PROJECT_API_KEY@your-lookout-host.example.com)',
                placeholder: 'https://…',
                required: true
            );
        }

        $parsed = LookoutDsn::parse($dsn);
        if ($parsed['api_key'] === null || $parsed['base_uri'] === null) {
            $this->components->error('Invalid DSN. Use: https://PROJECT_API_KEY@lookout-host.example.com (scheme + host required).');

            return self::FAILURE;
        }

        $body = File::get($envPath);
        $block = $this->formatEnvBlock($dsn, ! $this->option('no-quick'));

        if (preg_match('/^LOOKOUT_DSN=/m', $body)) {
            $this->components->warn('LOOKOUT_DSN is already present in .env; leaving the file unchanged. Edit .env manually or remove the existing line first.');

            return self::SUCCESS;
        }

        File::append($envPath, $block);
        $this->components->info('Lookout variables were appended to .env.');
        if (! $this->option('no-quick')) {
            $this->line('  • LOOKOUT_LARAVEL=true enables uncaught exception reporting and trace auto-flush defaults.');
        }
        $this->line('  • Optional: publish config with php artisan vendor:publish --tag=lookout-tracing-config');

        return self::SUCCESS;
    }

    /**
     * @return non-empty-string
     */
    private function formatEnvBlock(string $dsn, bool $includeLaravelQuick): string
    {
        $escaped = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\n', '\r'], $dsn);
        $lines = [
            '',
            '# Lookout (lookout/tracing package)',
            'LOOKOUT_DSN="'.$escaped.'"',
        ];
        if ($includeLaravelQuick) {
            $lines[] = 'LOOKOUT_LARAVEL=true';
        }

        return implode("\n", $lines)."\n";
    }
}
