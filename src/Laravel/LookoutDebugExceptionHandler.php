<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lookout\Tracing\Debug\RequestDetailsCollector;
use Lookout\Tracing\Debug\RouteSuggestions;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
                    Lookout::resolveDebugPageMeta($request, $e) + [
                        'privileged' => true,
                        'request_details' => (new RequestDetailsCollector)->collect($request),
                    ],
                );

                return new Response($html, 500, ['Content-Type' => 'text/html; charset=UTF-8']);
            } catch (Throwable) {
                // The debug page itself failed — never break the pipeline; fall
                // through to the host's normal rendering.
            }
        }

        if ($this->shouldRenderNotFoundPage($request, $e)) {
            try {
                return new Response(
                    $this->renderNotFoundPage($request),
                    404,
                    ['Content-Type' => 'text/html; charset=UTF-8'],
                );
            } catch (Throwable) {
                // fall through to the host's normal 404
            }
        }

        return $this->inner->render($request, $e);
    }

    /**
     * Smart 404 with "did you mean" route suggestions — dev tooling, so it
     * additionally requires the debug-page gate and never fires in test runs
     * (suites assert on the host app's own 404 rendering).
     */
    private function shouldRenderNotFoundPage(mixed $request, Throwable $e): bool
    {
        if (! $e instanceof NotFoundHttpException || ! Lookout::debugPageEnabled()) {
            return false;
        }
        if (! $request instanceof Request || app()->runningUnitTests()) {
            return false;
        }
        if ($request->expectsJson() || $request->isJson() || $request->hasHeader('X-Livewire')) {
            return false;
        }

        return Lookout::viewerMaySeeDebugPage($request, $e);
    }

    private function renderNotFoundPage(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');

        return view('lookout-tracing::debug.not-found', [
            'path' => $path,
            'method' => $request->getMethod(),
            'suggestions' => RouteSuggestions::rank($path, RouteSuggestions::registeredGetUris()),
            'appName' => config('app.name', 'App'),
        ])->render();
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
        // Livewire updates get the full debug HTML as the 500 body: the SDK's
        // overlay script (injected on parent pages) catches the failed update
        // and displays it in a modal. Checked before the JSON sniffs because
        // Livewire posts application/json. Skipped in test runs so Livewire
        // suites keep asserting the host app's own error behavior.
        if ($request->hasHeader('X-Livewire')) {
            return ! app()->runningUnitTests();
        }
        if ($request->expectsJson() || $request->isJson()) {
            return false;
        }

        return true;
    }
}
