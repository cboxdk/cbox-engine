<?php

declare(strict_types=1);

namespace Cbox\Engine\Platform;

use Cbox\Engine\Contracts\Kubernetes;
use Cbox\Engine\ValueObjects\ManifestDocument;
use RuntimeException;

/**
 * The certificate authority this machine trusts, kept OUTSIDE the cluster.
 *
 * BECAUSE TRUSTING ONE IS EXPENSIVE. Adding a root to the keychain costs a
 * password prompt and a moment of thought about what is being trusted, and it is
 * reasonable to ask that of somebody once. Asking again every time they run
 * `cbox destroy` is not: cert-manager would mint a fresh authority on the next
 * `cbox up`, every certificate under it would be signed by something the
 * machine has never seen, and the browser would go back to a full-page warning
 * with no explanation of what changed.
 *
 * So the authority is generated once, saved beside the developer's other
 * configuration, and SEEDED into every cluster after. cert-manager still issues
 * every leaf certificate from it — only the root is ours to keep.
 *
 * The private key is on disk, and that is the honest trade. It is a development
 * authority for `*.cbox.test` on one machine; the alternative is a machine that
 * cannot trust its own certificates, which is the whole feature.
 */
class LocalAuthority
{
    public function __construct(
        private readonly Kubernetes $kubernetes,
        private readonly string $directory,
    ) {}

    /** Where the certificate lives, for anything that needs to point at it. */
    public function certificatePath(): string
    {
        return $this->directory.'/ca.crt';
    }

    private function keyPath(): string
    {
        return $this->directory.'/ca.key';
    }

    /** Whether this machine already has an authority to seed. */
    public function exists(): bool
    {
        return is_file($this->certificatePath()) && is_file($this->keyPath());
    }

    /**
     * The saved authority as the Secret cert-manager's CA issuer reads.
     *
     * @return list<ManifestDocument>
     */
    public function seed(): array
    {
        if (! $this->exists()) {
            return [];
        }

        return [ManifestDocument::fromArray([
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'type' => 'kubernetes.io/tls',
            'metadata' => ['name' => ClusterObjects::CA_ISSUER, 'namespace' => 'cert-manager'],
            // Base64 rather than stringData: a key written as stringData can
            // never be removed by a later apply, and this Secret is the one
            // thing on the cluster whose exact contents matter.
            'data' => [
                'tls.crt' => base64_encode((string) file_get_contents($this->certificatePath())),
                'tls.key' => base64_encode((string) file_get_contents($this->keyPath())),
                'ca.crt' => base64_encode((string) file_get_contents($this->certificatePath())),
            ],
        ])];
    }

    /**
     * Save the authority the cluster just generated, if there is nothing saved.
     *
     * ONLY ONCE. Overwriting would replace an authority this machine may already
     * trust with one it does not, which is the failure this class exists to
     * prevent — and it would do it silently, on an ordinary `cbox up`.
     *
     * @return bool whether anything was written
     */
    public function capture(): bool
    {
        if ($this->exists()) {
            return false;
        }

        $secret = $this->kubernetes->read('secret', ClusterObjects::CA_ISSUER, 'cert-manager');

        if ($secret === null) {
            return false;
        }

        $data = $secret->body->data ?? null;
        $certificate = is_object($data) ? ($data->{'tls.crt'} ?? null) : null;
        $key = is_object($data) ? ($data->{'tls.key'} ?? null) : null;

        if (! is_string($certificate) || ! is_string($key)) {
            return false;
        }

        if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException("Could not create [{$this->directory}] to keep the authority in.");
        }

        file_put_contents($this->certificatePath(), (string) base64_decode($certificate, true));
        // 0600, because it signs every name on this machine. The directory is
        // 0700 for the same reason.
        file_put_contents($this->keyPath(), (string) base64_decode($key, true), LOCK_EX);
        chmod($this->keyPath(), 0600);

        return true;
    }

    /**
     * The fingerprint a person can compare against what their keychain shows.
     *
     * SHA-256 of the DER, which is what Keychain Access and `security` both
     * display. Printed rather than checked for them: this tool does not read
     * the keychain, and saying "trusted" without looking would be a claim it
     * cannot support.
     */
    public function fingerprint(): string
    {
        if (! $this->exists()) {
            return '';
        }

        $pem = (string) file_get_contents($this->certificatePath());
        $body = preg_replace('~-----(BEGIN|END) CERTIFICATE-----|\s~', '', $pem) ?? '';
        $der = base64_decode($body, true);

        if ($der === false || $der === '') {
            return '';
        }

        return strtoupper(implode(' ', str_split(hash('sha256', $der), 4)));
    }
}
