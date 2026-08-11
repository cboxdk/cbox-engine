<?php

declare(strict_types=1);

use Cbox\Engine\Platform\ClusterObjects;
use Cbox\Engine\Platform\LocalAuthority;
use Cbox\Engine\Tests\RecordingKubernetes;
use Cbox\Engine\ValueObjects\ManifestDocument;

/*
 * The authority this machine trusts.
 *
 * Adding a root to the keychain costs a password prompt and a moment of thought
 * about what is being trusted. Asking for that once is reasonable; asking again
 * after every `cbox destroy` is not.
 */

function authorityAt(RecordingKubernetes $kubernetes, string $suffix = ''): LocalAuthority
{
    return new LocalAuthority($kubernetes, sys_get_temp_dir().'/cbox-ca-'.getmypid().$suffix);
}

function clusterWithCa(RecordingKubernetes $kubernetes): void
{
    $kubernetes->secrets['cbox-ca'] = ManifestDocument::fromArray([
        'kind' => 'Secret',
        'metadata' => ['name' => 'cbox-ca', 'namespace' => 'cert-manager'],
        'data' => ['tls.crt' => base64_encode('CERT'), 'tls.key' => base64_encode('KEY')],
    ]);
}

it('keeps the authority the cluster minted, once', function (): void {
    $kubernetes = new RecordingKubernetes;
    clusterWithCa($kubernetes);

    $authority = authorityAt($kubernetes, '-keep');
    @unlink($authority->certificatePath());

    expect($authority->exists())->toBeFalse()
        ->and($authority->capture())->toBeTrue()
        ->and($authority->exists())->toBeTrue()
        ->and(file_get_contents($authority->certificatePath()))->toBe('CERT')
        // ONCE. Overwriting would replace a root this machine may already trust
        // with one it does not — silently, on an ordinary `cbox up`.
        ->and($authority->capture())->toBeFalse();
});

it('seeds a saved authority instead of letting cert-manager mint a new one', function (): void {
    // The whole point: `cbox destroy` and `cbox up` would otherwise produce a
    // root the machine has never seen, and the browser goes back to a full-page
    // warning that says nothing about what changed.
    $kubernetes = new RecordingKubernetes;
    clusterWithCa($kubernetes);

    $authority = authorityAt($kubernetes, '-seed');
    @unlink($authority->certificatePath());
    $authority->capture();

    $manifests = (new ClusterObjects($authority))->manifests();
    $kinds = [];

    foreach ($manifests as $manifest) {
        $kinds[$manifest->kind().'/'.$manifest->name()] = true;
    }

    expect($kinds)->toHaveKey('Secret/cbox-ca')
        ->and($kinds)->toHaveKey('ClusterIssuer/cbox-ca')
        // No Certificate and no self-signed issuer: there is nothing to issue,
        // and re-issuing the root is precisely what must not happen.
        ->and($kinds)->not->toHaveKey('Certificate/cbox-ca')
        ->and($kinds)->not->toHaveKey('ClusterIssuer/cbox-selfsigned');
});

it('lets cert-manager mint one when this machine has none', function (): void {
    $authority = authorityAt(new RecordingKubernetes, '-none');
    @unlink($authority->certificatePath());
    @unlink(dirname($authority->certificatePath()).'/ca.key');

    $kinds = [];

    foreach ((new ClusterObjects($authority))->manifests() as $manifest) {
        $kinds[$manifest->kind().'/'.$manifest->name()] = true;
    }

    expect($kinds)->toHaveKey('Certificate/cbox-ca')
        ->and($kinds)->toHaveKey('ClusterIssuer/cbox-selfsigned');
});

it('keeps the private key to itself', function (): void {
    // It signs every name on this machine.
    $kubernetes = new RecordingKubernetes;
    clusterWithCa($kubernetes);

    $authority = authorityAt($kubernetes, '-perms');
    @unlink($authority->certificatePath());
    $authority->capture();

    $key = dirname($authority->certificatePath()).'/ca.key';

    expect(substr(sprintf('%o', fileperms($key)), -3))->toBe('600');
});
