<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Decorates the host app's exception handler. For genuine server errors on
 * full-page HTML requests, when the debug page is enabled AND the viewer is
 * authorized (see {@see Lookout}), it renders the interactive debug page
 * instead of the branded error. Everything else — 4xx, validation, JSON/API,
 * Livewire, console — delegates untouched to the wrapped handler, so existing
 * render closures and error pages keep working.
 *
 * This fires regardless of `config('app.debug')`: that's the whole point —
 * production stays in production mode for everyone except the gated viewer.
 */
final class LookoutDebugExceptionHandler implements ExceptionHandler
{
    public function __construct(private readonly ExceptionHandler $inner) {}

    /**
     * Proxy any method beyond the ExceptionHandler contract to the wrapped
     * handler — Pulse, Telescope, and app bootstrap call fluent helpers
     * (reportable(), renderable(), map(), dontReport(), ignore(), …) that live
     * on the concrete Foundation handler, not the interface. Preserving them is
     * what makes this decorator transparent.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }

    public function report(Throwable $e): void
    {
        $this->inner->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, Throwable $e): Response
    {
        if ($this->shouldRenderDebugPage($request, $e)) {
            try {
                $html = Lookout::debugPageRenderer()->renderThrowable(
                    $e,
                    app(),
                    Lookout::resolveDebugPageMeta($request, $e) + ['privileged' => true],
                );

                return new Response($html, 500, ['Content-Type' => 'text/html; charset=UTF-8']);
            } catch (Throwable) {
                // The debug page itself failed — never break the pipeline; fall
                // through to the host's normal rendering.
            }
        }

        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->inner->renderForConsole($output, $e);
    }

    private function shouldRenderDebugPage(mixed $request, Throwable $e): bool
    {
        if (! Lookout::debugPageEnabled() || ! $request instanceof Request) {
            return false;
        }
        if (! $this->isServerError($e) || ! $this->wantsHtmlPage($request)) {
            return false;
        }

        return Lookout::viewerMaySeeDebugPage($request, $e);
    }

    /**
     * Only "something actually broke" (would-be-500s). 4xx HttpExceptions,
     * validation (422), auth (401/redirect), and authorization pass through.
     */
    private function isServerError(Throwable $e): bool
    {
        if ($e instanceof ValidationException
            || $e instanceof AuthenticationException
            || $e instanceof AuthorizationException) {
            return false;
        }
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode() >= 500;
        }

        return true;
    }

    private function wantsHtmlPage(Request $request): bool
    {
        if ($request->expectsJson() || $request->isJson()) {
            return false;
        }
        // Livewire updates are XHR and can't display a full HTML page — out of scope for v1.
        if ($request->hasHeader('X-Livewire')) {
            return false;
        }

        return true;
    }
}
