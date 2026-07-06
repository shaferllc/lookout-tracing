<?php

declare(strict_types=1);

namespace Lookout\Tracing\Debug;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Lookout\Tracing\Support\DataRedactor;
use Throwable;

/**
 * Builds the redacted request panel for the local debug page: method, URL,
 * matched route (name / action / middleware), headers, query, body, cookies,
 * session, and files.
 *
 * This is render-time-only data — it is never merged into the ingest payload
 * and never leaves the machine, which is why it can be richer than what gets
 * reported (Ignition-style). Everything value-bearing still passes through
 * {@see DataRedactor} so a screen-shared debug page doesn't leak credentials.
 */
final class RequestDetailsCollector
{
    /** Cap any single rendered value so one giant field can't blow up the page. */
    private const MAX_VALUE_LENGTH = 4096;

    /**
     * @return array<string, mixed>
     */
    public function collect(Request $request): array
    {
        $details = [];

        try {
            $details['method'] = $request->getMethod();
            $details['url'] = substr($request->fullUrl(), 0, 2048);
            $details['ip'] = (string) $request->ip();

            $ua = $request->userAgent();
            if (is_string($ua) && $ua !== '') {
                $details['user_agent'] = substr($ua, 0, 512);
            }
            $referer = $request->headers->get('referer');
            if (is_string($referer) && $referer !== '') {
                $details['referer'] = substr($referer, 0, 2048);
            }
        } catch (Throwable) {
            return $details;
        }

        $details['route'] = $this->routeInfo($request);
        $details['headers'] = $this->headers($request);
        $details['query'] = $this->clean($request->query());
        $details['body'] = $this->body($request);
        $details['cookies'] = $this->clean($request->cookies->all());
        $details['session'] = $this->session($request);
        $details['files'] = $this->files($request);

        return array_filter($details, static fn ($v): bool => $v !== null && $v !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private function routeInfo(Request $request): array
    {
        try {
            $route = $request->route();
        } catch (Throwable) {
            return [];
        }
        if (! $route instanceof Route) {
            return [];
        }

        $info = [];
        $name = $route->getName();
        if (is_string($name) && $name !== '') {
            $info['name'] = $name;
        }
        $action = $route->getActionName();
        if (is_string($action) && $action !== '') {
            $info['action'] = $action;
        }
        $info['uri'] = $route->uri();

        try {
            $middleware = $route->gatherMiddleware();
            if ($middleware !== []) {
                $info['middleware'] = array_values(array_map(
                    static fn ($m): string => is_string($m) ? $m : (is_object($m) ? $m::class : 'closure'),
                    $middleware,
                ));
            }
        } catch (Throwable) {
            // middleware resolution can throw mid-exception; skip
        }

        $params = $route->parameters();
        if ($params !== []) {
            $info['parameters'] = $this->clean(array_map(
                static fn ($p) => is_object($p) ? $p::class.(method_exists($p, 'getKey') ? '#'.$p->getKey() : '') : $p,
                $params,
            ));
        }

        return $info;
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $out = [];
        foreach ($request->headers->all() as $key => $values) {
            $value = implode(', ', array_filter($values, 'is_string'));
            $out[$key] = $value;
        }

        /** @var array<string, string> */
        return $this->clean($out);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function body(Request $request): ?array
    {
        try {
            if ($request->isJson()) {
                $json = $request->json()->all();

                return $json === [] ? null : $this->clean($json);
            }
            $post = $request->request->all();

            return $post === [] ? null : $this->clean($post);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function session(Request $request): ?array
    {
        try {
            if (! $request->hasSession()) {
                return null;
            }
            $data = $request->session()->all();
        } catch (Throwable) {
            return null;
        }

        return $data === [] ? null : $this->clean($data);
    }

    /**
     * @return list<string>
     */
    private function files(Request $request): array
    {
        $names = [];
        try {
            foreach ($request->allFiles() as $key => $file) {
                $names[] = (string) $key;
            }
        } catch (Throwable) {
            return [];
        }

        return $names;
    }

    /**
     * Redact then truncate for display.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function clean(array $data): array
    {
        return $this->truncate(DataRedactor::redact($data));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function truncate(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->truncate($value);
            } elseif (is_string($value) && strlen($value) > self::MAX_VALUE_LENGTH) {
                $data[$key] = substr($value, 0, self::MAX_VALUE_LENGTH).'… [truncated]';
            }
        }

        return $data;
    }
}
