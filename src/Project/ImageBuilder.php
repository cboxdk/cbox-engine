<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Engine\Contracts\CommandRunner;
use Cbox\Engine\Kind\KindCluster;
use RuntimeException;

/**
 * Building a project's own image, for the applications that have one.
 *
 * NOT EVERY APPLICATION RUNS ON A BASE IMAGE, and pretending otherwise was the
 * assumption that would have made this product useless for half the estate.
 * One application here brings a Dockerfile with its own nginx, its own redis and supervisor
 * on debian-slim; nothing about the Cbox base image serves it, and a platform
 * whose answer is "port it first" is a platform nobody ports anything to.
 *
 * BUILT HERE AND SIDE-LOADED, never pushed. There is no registry on a laptop,
 * and a local platform that needed one would need credentials, a network and a
 * decision about where the developer's unfinished work is stored. `kind load`
 * copies the image into the node's own containerd, which is what it is for.
 *
 * THE TAG CARRIES THE IMAGE'S ID. A fixed tag means the Deployment's pod spec
 * never changes, so a rebuilt image never rolls out — the pod keeps running the
 * layers it started with and every edit appears to do nothing. Learned on
 * Cortex, where a fixed CAPI template name meant a change never reached a node.
 */
class ImageBuilder
{
    public function __construct(private readonly CommandRunner $runner) {}

    /**
     * Build the project's image and put it where the cluster can see it.
     *
     * @param  callable(string): void|null  $onOutput  the build's own output, as it happens
     * @return string the image reference to run
     */
    public function build(BuildSpec $spec, string $project, ?callable $onOutput = null): string
    {
        // A placeholder tag for the build itself: the real one cannot be known
        // until the image exists and has an id.
        $staging = 'cbox-local/'.$project.':building';

        $arguments = ['docker', 'build', '-t', $staging, '-f', $spec->dockerfile];

        if ($spec->target !== '') {
            $arguments = [...$arguments, '--target', $spec->target];
        }

        foreach ($spec->args as $name => $value) {
            $arguments = [...$arguments, '--build-arg', $name.'='.$value];
        }

        $arguments[] = $spec->context;

        $built = $onOutput === null
            ? $this->runner->run($arguments, timeout: 3600)
            : $this->runner->stream($arguments, $onOutput, timeout: null);

        if (! $built->successful()) {
            throw new RuntimeException(
                "The image for [{$project}] did not build.\n      "
                .trim($built->errorOutput ?: $built->text()),
            );
        }

        $reference = 'cbox-local/'.$project.':'.$this->digest($staging);

        $tagged = $this->runner->run(['docker', 'tag', $staging, $reference], timeout: 60);

        if (! $tagged->successful()) {
            throw new RuntimeException("Could not tag the image for [{$project}].");
        }

        $loaded = $this->runner->run(
            ['kind', 'load', 'docker-image', $reference, '--name', KindCluster::NAME],
            timeout: 900,
        );

        if (! $loaded->successful()) {
            throw new RuntimeException(
                "The image built and could not be loaded into the cluster.\n      "
                .trim($loaded->errorOutput ?: $loaded->text()),
            );
        }

        return $reference;
    }

    /**
     * The image's own id, short enough to read in a pod spec.
     *
     * The ID, not a hash of the Dockerfile: two builds of the same Dockerfile
     * differ whenever anything they COPY differs, and a tag that did not change
     * with the layers is the whole bug this exists to avoid.
     */
    private function digest(string $reference): string
    {
        $result = $this->runner->run(
            ['docker', 'image', 'inspect', $reference, '--format', '{{.Id}}'],
            timeout: 60,
        );

        $id = trim($result->text());

        if (! $result->successful() || $id === '') {
            throw new RuntimeException('The image built and then could not be inspected.');
        }

        return substr(str_replace('sha256:', '', $id), 0, 16);
    }
}
