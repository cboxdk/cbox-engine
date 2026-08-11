<?php

declare(strict_types=1);

namespace Cbox\Engine\Push;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * Cortex's public `/v1` surface, as this tool needs it.
 *
 * A CLIENT, and nothing more. Cortex already has the API — projects,
 * environments, services, plan — so pushing does not need a single line of new
 * production code, and this deliberately adds none. Anything it cannot do
 * through the documented surface it does not do.
 *
 * It never applies. Cortex's own design is that changing a service's intent
 * lands as a plan difference and applying it stays an explicit act — so editing
 * an image in a form is not the same gesture as shipping it. A local tool that
 * shipped straight to production would be the one exception to that, from the
 * machine with the least context about what else is going on.
 */
class CortexClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    /**
     * The organization's projects, by name.
     *
     * @return array<string, string> name => id
     */
    public function projects(): array
    {
        return $this->index('/v1/projects');
    }

    /**
     * @return array<string, string> name => id
     */
    public function environments(string $projectId): array
    {
        return $this->index("/v1/projects/{$projectId}/environments");
    }

    /**
     * @return array<string, string> name => id
     */
    public function services(string $environmentId): array
    {
        return $this->index("/v1/environments/{$environmentId}/services");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return string the service id
     */
    public function createService(string $environmentId, array $payload): string
    {
        $response = $this->request()->post("/v1/environments/{$environmentId}/services", $payload);

        if ($response->status() !== 201) {
            throw new RuntimeException($this->failure($response->status(), $response->body()));
        }

        $id = data_get($response->json(), 'data.id') ?? data_get($response->json(), 'id');

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Cortex created the service but did not say which one.');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateService(string $serviceId, array $payload): void
    {
        // `domain` is a create-time convenience on that endpoint and not a
        // column; sending it to update would be sending a field nothing reads.
        unset($payload['domain']);

        $response = $this->request()->put("/v1/services/{$serviceId}", $payload);

        if (! $response->successful()) {
            throw new RuntimeException($this->failure($response->status(), $response->body()));
        }
    }

    /**
     * What Cortex would change, without changing it.
     *
     * @return array<string, mixed>
     */
    public function plan(string $serviceId): array
    {
        $response = $this->request()->post("/v1/services/{$serviceId}/plan");

        if (! $response->successful()) {
            throw new RuntimeException($this->failure($response->status(), $response->body()));
        }

        /** @var mixed $plan */
        $plan = $response->json();

        if (! is_array($plan)) {
            return [];
        }

        /** @var array<string, mixed> $plan */
        return $plan;
    }

    /**
     * @return array<string, string>
     */
    private function index(string $path): array
    {
        $response = $this->request()->get($path);

        if (! $response->successful()) {
            throw new RuntimeException($this->failure($response->status(), $response->body()));
        }

        /** @var mixed $rows */
        $rows = data_get($response->json(), 'data') ?? $response->json();

        $found = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            $id = is_array($row) ? ($row['id'] ?? null) : null;

            if (is_string($name) && is_string($id)) {
                $found[$name] = $id;
            }
        }

        return $found;
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->token)
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * Cortex's own words, and the one status that has a specific answer.
     */
    private function failure(int $status, string $body): string
    {
        if ($status === 401) {
            return 'Cortex did not accept the token. It is a cbox-id user API token, and it has to '
                .'belong to the organization you are pushing into.';
        }

        if ($status === 403) {
            return 'That token can read but not write. Pushing needs a token with write scope.';
        }

        return "Cortex answered {$status}: ".trim($body);
    }
}
