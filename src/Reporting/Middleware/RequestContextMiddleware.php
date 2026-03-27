<?php

declare(strict_types=1);

namespace Lookout\Tracing\Reporting\Middleware;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Lookout\Tracing\Reporting\ReportMiddlewareInterface;
use Throwable;

/**
 * HTTP URL, route name, authenticated user, and server name for web requests.
 */
final class RequestContextMiddleware implements ReportMiddlewareInterface
{
    public function __construct(
        private ?Application $app = null,
    ) {}

    public function handle(array $payload): array
    {
        $app = $this->app;
        if ($app === null && function_exists('app')) {
            try {
                $app = app();
            } catch (Throwable) {
                return $payload;
            }
        }
        if ($app === null || $app->runningInConsole()) {
            return $payload;
        }

        try {
            if (! $app->bound('request')) {
                return $payload;
            }
            $req = $app->make('request');
            if (! $req instanceof Request) {
                return $payload;
            }
        } catch (Throwable) {
            return $payload;
        }

        if (empty($payload['url']) && $req->fullUrl() !== '') {
            $payload['url'] = substr($req->fullUrl(), 0, 2048);
        }

        $route = $req->route();
        if ($route !== null) {
            $name = $route->getName();
            if (is_string($name) && $name !== '' && empty($payload['issue_route'])) {
                $payload['issue_route'] = substr($name, 0, 512);
            }
        }

        if (empty($payload['user']) && ($u = $req->user()) !== null) {
            $userRow = [];
            if (method_exists($u, 'getAuthIdentifier')) {
                $id = $u->getAuthIdentifier();
                if ($id !== null && (is_string($id) || is_int($id))) {
                    $userRow['id'] = (string) $id;
                }
            }
            foreach (['email', 'name', 'username'] as $prop) {
                if (isset($u->{$prop}) && is_string($u->{$prop}) && $u->{$prop} !== '') {
                    $userRow[$prop] = substr($u->{$prop}, 0, 256);
                }
            }
            if ($userRow !== []) {
                $payload['user'] = $userRow;
            }
        }

        if (empty($payload['server_name'])) {
            $host = $req->getHost();
            if (is_string($host) && $host !== '') {
                $payload['server_name'] = substr($host, 0, 128);
            }
        }

        $ctx = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : [];
        $server = isset($ctx['server']) && is_array($ctx['server']) ? $ctx['server'] : [];
        $addr = $req->server('SERVER_ADDR');
        if (is_string($addr) && $addr !== '' && filter_var($addr, FILTER_VALIDATE_IP)) {
            $server['server_addr'] = $addr;
        }
        if ($server !== []) {
            $ctx['server'] = $server;
            $payload['context'] = $ctx;
        }

        return $payload;
    }
}
