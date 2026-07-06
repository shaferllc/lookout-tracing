<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Appends the Lookout debug-overlay <script> to full HTML pages so failed
 * Livewire updates can display the debug page in a modal. Pushed onto the web
 * group only when the debug page is enabled (see the service provider) —
 * production installs without the page never see this middleware.
 */
final class DebugOverlayScriptMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (! $this->isInjectableHtml($request, $response)) {
                return $response;
            }

            $content = $response->getContent();
            if (! is_string($content) || str_contains($content, 'lookout-debug-overlay')) {
                return $response;
            }

            $pos = strripos($content, '</body>');
            if ($pos === false) {
                return $response;
            }

            $tag = '<script src="/_lookout/overlay.js" defer id="lookout-debug-overlay"></script>';
            $response->setContent(substr($content, 0, $pos).$tag.substr($content, $pos));
        } catch (Throwable) {
            // never break responses over a dev overlay
        }

        return $response;
    }

    private function isInjectableHtml(Request $request, Response $response): bool
    {
        if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
            return false;
        }
        if ($response->getStatusCode() >= 500) {
            return false; // the debug page itself
        }
        $type = (string) $response->headers->get('Content-Type', '');

        return $type === '' || str_contains(strtolower($type), 'text/html');
    }
}
