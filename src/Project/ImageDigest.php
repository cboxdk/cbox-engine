<?php

declare(strict_types=1);

namespace Cbox\Engine\Project;

use Cbox\Engine\Contracts\CommandRunner;

/**
 * A tag resolved to the content it points at, at the moment of deploying.
 *
 * BECAUSE A TAG IS A MOVING TARGET AND A NODE NEVER LOOKS AGAIN. Kubernetes
 * defaults to `imagePullPolicy: IfNotPresent` for anything that is not
 * `:latest`, so once a node has pulled `php-fpm-nginx:8.5-bookworm` it keeps
 * those layers forever. Rebuild and republish that tag and every project on this
 * machine goes on running the old one — through a redeploy, through
 * `--recreate`, through a pod restart. Measured: a fix published to the registry
 * was invisible to a running project until the image was deleted from the node
 * by hand.
 *
 * IT ALSO MAKES THE ROLLOUT HAPPEN. The pod spec is the same text before and
 * after a republish, so nothing about the Deployment changes and Kubernetes has
 * no reason to restart anything. A digest changes when the content changes,
 * which is the whole mechanism — the same lesson Cortex learned from a fixed
 * CAPI template name that meant a change never reached a node.
 *
 * IT NEVER BLOCKS A DEPLOY. A laptop on a train cannot reach a registry, and
 * refusing to deploy over that would be worse than deploying the layers already
 * on the node. Resolution failing leaves the tag exactly as it was.
 */
class ImageDigest
{
    public function __construct(private readonly CommandRunner $runner) {}

    /**
     * The same reference, pinned to a digest, when that can be found out.
     *
     * Left alone when there is nothing to pin: an image already named by digest,
     * a locally built one that never came from a registry, and an empty string
     * for a project that has no image of its own.
     */
    public function pin(string $image): string
    {
        if ($image === '' || str_contains($image, '@sha256:') || str_starts_with($image, self::LOCAL_PREFIX)) {
            return $image;
        }

        $result = $this->runner->run(
            ['docker', 'buildx', 'imagetools', 'inspect', '--format', '{{json .Manifest.Digest}}', $image],
            timeout: 60,
        );

        if (! $result->successful()) {
            return $image;
        }

        $digest = trim($result->text(), " \n\r\t\"");

        if (! str_starts_with($digest, 'sha256:')) {
            return $image;
        }

        // The repository without its tag, then the digest. `foo:8.5@sha256:…` is
        // legal and docker accepts it, but it reads as though the tag still
        // decides something.
        $repository = $this->repository($image);

        return $repository.'@'.$digest;
    }

    /** Images this machine built, which have no registry to ask. */
    public const LOCAL_PREFIX = 'cbox-local/';

    private function repository(string $image): string
    {
        // A colon in the registry host is a PORT, not a tag —
        // `localhost:5000/app:1.0`. Only a colon after the last slash is a tag.
        $slash = strrpos($image, '/');
        $colon = strrpos($image, ':');

        if ($colon === false || ($slash !== false && $colon < $slash)) {
            return $image;
        }

        return substr($image, 0, $colon);
    }
}
