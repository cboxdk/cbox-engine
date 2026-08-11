<?php

declare(strict_types=1);

namespace Cbox\Engine\Addons;

use Cbox\Engine\ValueObjects\ManifestDocument;
use RuntimeException;

/**
 * The addons the local cluster cannot function without, as plain manifests.
 *
 * RENDERED AHEAD OF TIME, not installed by helm. Cortex learned this on a live
 * tenant: a helm run killed mid-install leaves a release recorded
 * `pending-install`, helm refuses that release on every later attempt, and
 * everything after it in the script never installs. A cluster then reports
 * itself healthy while three addons are simply absent.
 *
 * What a chart produces is a set of manifests. Applied as ordinary desired
 * state they converge on every run, and nothing on the developer's machine has
 * to hold a lock for any of it.
 *
 * IN THIS ORDER, and it is not alphabetical. The Gateway API bundle defines the
 * kinds Envoy Gateway watches; cert-manager is last because it is the only one
 * that is optional to the others.
 */
class AddonSet
{
    private const ORDER = ['gateway-api-crds', 'envoy-gateway', 'cert-manager', 'cnpg', 'keda', 'keda-add-ons-http'];

    public function __construct(private readonly string $directory) {}

    /** @return list<string> */
    public function names(): array
    {
        return self::ORDER;
    }

    /**
     * @return list<ManifestDocument>
     */
    public function manifests(string $name): array
    {
        $path = $this->directory.'/'.$name.'.json';

        if (! is_file($path)) {
            throw new RuntimeException(
                "The addon [{$name}] has not been rendered. Run bin/render-addons.sh."
            );
        }

        // Read through ManifestDocument, which does NOT decode into associative
        // arrays — that flattens every empty object in the bundle, and the
        // Gateway API's CRDs contain several. See ManifestDocument.
        return ManifestDocument::listFromJson((string) file_get_contents($path));
    }

    /** The versions these were rendered from, for anything that reports them. */
    /** @return array<string, string> */
    public function versions(): array
    {
        $path = $this->directory.'/rendered.json';

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, string> $versions */
        $versions = array_filter($decoded, is_string(...));

        return $versions;
    }
}
