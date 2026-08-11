<?php

declare(strict_types=1);

namespace Cbox\Engine\Console;

use Cbox\Engine\Project\ProjectLocator;
use Cbox\Engine\Push\CortexClient;
use Cbox\Engine\Push\PushPayload;
use Cbox\Engine\Support\Env;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory;
use RuntimeException;
use Throwable;

/**
 * Send this project's definition to Cortex.
 *
 * WHAT TRAVELS IS THE MANIFEST, not the environment. Cortex fills in what only
 * it can know — the cluster, the real hostname, the placement, the credentials —
 * and most of what an application reads does not travel at all, because it is a
 * binding the platform resolves on both sides. Secrets never travel; they were
 * never in the file.
 *
 * IT DOES NOT DEPLOY. Cortex's own design is that changing intent lands as a
 * plan difference and applying it stays an explicit act, so that editing an
 * image is not the same gesture as shipping it. A laptop shipping straight to
 * production would be the single exception to that, from the machine with the
 * least context about what else is going on. So this prints the plan and stops.
 */
class PushCommand extends Command
{
    use LocatesAProject;

    protected $signature = 'local:push
                            {--path= : The directory to look in, defaulting to this one}
                            {--env= : Which local environment to read, defaulting to the worktree you are in}
                            {--project= : The Cortex project, by name}
                            {--environment= : The Cortex environment, by name}
                            {--domain= : The production hostname, when the service needs one}
                            {--json : Machine-readable output}';

    protected $description = 'Send this project\'s definition to Cortex and show the plan';

    /** An option as the string it is meant to be, and empty when it is anything else. */
    private function optionString(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }

    public function handle(ProjectLocator $locator, Factory $http): int
    {
        $manifest = $this->locateProject($locator);

        if ($manifest === null) {
            return self::FAILURE;
        }

        try {
            $client = new CortexClient($http, $this->baseUrl(), $this->token());
            $payload = new PushPayload($manifest);

            // is_string rather than a cast: `--project` is `array|bool|float|
            // int|string|null` as far as the console knows, and casting an
            // array to string is a fatal, not a fallback.
            $project = $this->resolve('project', $client->projects(), $this->optionString('project'));
            $environment = $this->resolve(
                'environment',
                $client->environments($project),
                $this->optionString('environment'),
            );

            $domain = $this->optionString('domain');

            if ($manifest->scaleToZero && $domain === '') {
                throw new RuntimeException(
                    'This project scales to zero, which means the wake is a request arriving — so it '
                    .'needs a production hostname. Name one with --domain. The local ones do not travel.',
                );
            }

            $services = $client->services($environment);
            $existing = $services[$manifest->name] ?? null;

            $service = $existing ?? $client->createService($environment, $payload->toArray($domain));

            if ($existing !== null) {
                $client->updateService($existing, $payload->toArray());
            }

            $plan = $client->plan($service);
        } catch (Throwable $e) {
            return $this->refuse($e->getMessage());
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'name' => $manifest->name,
                'service' => $service,
                'created' => $existing === null,
                'omitted' => $payload->omitted(),
                'plan' => $plan,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line($existing === null
            ? "  <fg=green>✓</> {$manifest->name} created in Cortex."
            : "  <fg=green>✓</> {$manifest->name} updated in Cortex.");

        // NAMED, NOT SILENT. A push that quietly drops half a manifest is one
        // somebody will believe carried all of it, and they find out when their
        // production service answers on no hostname.
        foreach ($payload->omitted() as $omitted) {
            $this->line("      <fg=gray>not sent:</> {$omitted}");
        }

        $this->newLine();
        $this->line('  '.$this->summarise($plan));
        $this->newLine();
        $this->line('  Nothing has been applied. Cortex applies a plan; this only wrote the intent.');

        return self::SUCCESS;
    }

    /**
     * Cortex's plan, in one line.
     *
     * @param  array<string, mixed>  $plan
     */
    private function summarise(array $plan): string
    {
        /** @var mixed $changes */
        $changes = data_get($plan, 'data.changes') ?? data_get($plan, 'changes');

        if (! is_array($changes)) {
            return 'Cortex has the change. Open the plan there to see it.';
        }

        if ($changes === []) {
            // Worth saying plainly: it means the push changed nothing, which is
            // either reassuring or the sign that somebody pushed the wrong
            // directory.
            return 'The plan is empty — production already matches this manifest.';
        }

        return count($changes).' change'.(count($changes) === 1 ? '' : 's').' waiting in Cortex.';
    }

    /**
     * Turn a name into an id, or say what the choices were.
     *
     * @param  array<string, string>  $available
     */
    private function resolve(string $what, array $available, string $named): string
    {
        if ($available === []) {
            throw new RuntimeException("That token can see no {$what}s. Is it for the right organization?");
        }

        if ($named === '') {
            if (count($available) === 1) {
                return (string) reset($available);
            }

            throw new RuntimeException(
                "There is more than one {$what}, so name it with --{$what}: ".implode(', ', array_keys($available)),
            );
        }

        if (! isset($available[$named])) {
            throw new RuntimeException(
                "There is no {$what} called [{$named}]. There is: ".implode(', ', array_keys($available)),
            );
        }

        return $available[$named];
    }

    private function baseUrl(): string
    {
        $url = Env::string('CBOX_CORTEX_URL', '');

        if ($url === '') {
            throw new RuntimeException(
                'Set CBOX_CORTEX_URL to the Cortex this pushes into. There is no default on purpose: '
                .'guessing which installation somebody means is not a mistake worth making silently.',
            );
        }

        return $url;
    }

    /**
     * The bearer token, from the environment.
     *
     * NO LOGIN FLOW HERE, deliberately. Authentication belongs to cbox-id, and
     * a second implementation of it living in a developer tool is a second thing
     * to keep correct and a second place tokens can be stored badly. Until
     * cbox-id ships a CLI, this reads a token somebody already has.
     */
    private function token(): string
    {
        $token = Env::string('CBOX_TOKEN', '');

        if ($token === '') {
            throw new RuntimeException(
                'Set CBOX_TOKEN to a cbox-id user API token with write scope. Pushing changes what runs '
                .'in production, so it is not something this can do on an unauthenticated guess.',
            );
        }

        return $token;
    }
}
