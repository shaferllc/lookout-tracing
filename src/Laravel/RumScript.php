<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Lookout\Tracing\HtmlTraceMeta;
use Lookout\Tracing\Tracer;

/**
 * Browser RUM script URL and init payload for Blade layouts.
 */
final class RumScript
{
    public static function enabled(): bool
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg) || ! empty($cfg['disabled'])) {
            return false;
        }

        $rum = is_array($cfg['rum'] ?? null) ? $cfg['rum'] : [];
        if (empty($rum['enabled'])) {
            return false;
        }

        $apiKey = $cfg['api_key'] ?? null;
        $base = isset($cfg['base_uri']) && is_string($cfg['base_uri']) ? rtrim(trim($cfg['base_uri']), '/') : '';
        if (! is_string($apiKey) || $apiKey === '' || $base === '') {
            return false;
        }

        if (! Tracer::instance()->isPerformanceEnabled()) {
            return false;
        }

        return true;
    }

    public static function scriptPath(): string
    {
        return dirname(__DIR__, 2).'/resources/rum/lookout-rum.js';
    }

    public static function endpointUrl(): string
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg)) {
            return '';
        }
        $base = isset($cfg['base_uri']) && is_string($cfg['base_uri']) ? rtrim(trim($cfg['base_uri']), '/') : '';
        $rum = is_array($cfg['rum'] ?? null) ? $cfg['rum'] : [];
        $path = isset($rum['ingest_path']) && is_string($rum['ingest_path']) ? $rum['ingest_path'] : '/api/ingest/rum';

        return $base !== '' ? $base.'/'.ltrim($path, '/') : '';
    }

    /**
     * @return array<string, mixed>
     */
    public static function initConfig(): array
    {
        $cfg = config('lookout-tracing');
        if (! is_array($cfg)) {
            return [];
        }

        $rum = is_array($cfg['rum'] ?? null) ? $cfg['rum'] : [];
        $release = isset($cfg['release']) && is_string($cfg['release']) ? trim($cfg['release']) : '';
        $env = isset($cfg['environment']) && is_string($cfg['environment']) ? trim($cfg['environment']) : '';

        return array_filter([
            'endpoint' => self::endpointUrl(),
            'apiKey' => is_string($cfg['api_key'] ?? null) ? $cfg['api_key'] : '',
            'environment' => $env !== '' ? $env : null,
            'release' => $release !== '' ? $release : null,
            'livewireNavigate' => (bool) ($rum['livewire_navigate'] ?? true),
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    public static function traceMetaHtml(): string
    {
        if (! Tracer::instance()->isPerformanceEnabled()) {
            return '';
        }

        return HtmlTraceMeta::render();
    }
}
