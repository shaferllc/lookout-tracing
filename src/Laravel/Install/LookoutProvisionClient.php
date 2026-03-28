<?php

declare(strict_types=1);

namespace Lookout\Tracing\Laravel\Install;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Lookout\Tracing\Laravel\Console\InstallLookoutCommand;

/**
 * Calls Lookout REST endpoints used by {@see InstallLookoutCommand}
 * to list the signed-in user and create a project (Sanctum bearer = user API token from Profile → API tokens).
 */
final class LookoutProvisionClient
{
    /**
     * @param  non-empty-string  $normalizedOrigin  e.g. {@code https://lookout.example.com} (no path)
     */
    private function __construct(
        private readonly string $normalizedOrigin,
        private readonly string $bearerToken,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function fromUserInput(string $baseUriInput, string $bearerToken): self
    {
        $origin = self::normalizeOriginOrNull($baseUriInput);
        if ($origin === null) {
            throw new InvalidArgumentException('Invalid Lookout base URL. Use https://your-lookout-host.example.com (scheme and host required).');
        }
        $token = trim($bearerToken);
        if ($token === '') {
            throw new InvalidArgumentException('API token is required.');
        }

        return new self($origin, $token);
    }

    /**
     * @return non-empty-string|null
     */
    public static function normalizeOriginOrNull(string $baseUriInput): ?string
    {
        $u = trim($baseUriInput);
        if ($u === '') {
            return null;
        }
        if (! str_contains($u, '://')) {
            $u = 'https://'.$u;
        }
        $parts = parse_url($u);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    /**
     * @return array<string, mixed>|null Decoded {@code data} from {@code GET /api/v1/me}, or null on failure / missing shape.
     */
    public function fetchMe(): ?array
    {
        $response = $this->jsonClient()->get('/api/v1/me');

        return $this->dataOrNull($response);
    }

    /**
     * @param  list<string>  $techStacks
     */
    public function createProject(string $organizationId, string $name, array $techStacks): Response
    {
        return $this->jsonClient()->post('/api/v1/projects', [
            'name' => $name,
            'organization_id' => $organizationId,
            'tech_stacks' => array_values($techStacks),
        ]);
    }

    /**
     * @return non-empty-string
     */
    public function normalizedBaseUriForDsn(): string
    {
        return $this->normalizedOrigin;
    }

    private function jsonClient(): PendingRequest
    {
        return Http::baseUrl($this->normalizedOrigin)
            ->withToken($this->bearerToken)
            ->acceptJson()
            ->timeout(30)
            ->connectTimeout(10);
    }

    private function dataOrNull(Response $response): ?array
    {
        if (! $response->successful()) {
            return null;
        }
        $data = $response->json('data');

        return is_array($data) ? $data : null;
    }
}
