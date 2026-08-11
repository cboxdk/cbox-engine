<?php

declare(strict_types=1);

namespace Cbox\Engine\Push;

use Cbox\Engine\Project\ProjectManifest;

/**
 * What travels to production, and — the harder half — what does not.
 *
 * THE MANIFEST TRAVELS, NOT THE ENVIRONMENT. Cortex fills in what only it can
 * know: the cluster, the real hostname, the placement, the credentials. So this
 * is a deliberately short list, and every omission below is a decision rather
 * than an oversight.
 *
 * NOT SENT, and why:
 *
 * - **Domains.** They are `*.cbox.test` names that exist on one laptop. Sending
 *   them would put a hostname nothing can resolve on a production service, and
 *   the wrong one is worse than none: a domain is how traffic arrives.
 * - **The computed URL variable.** `APP_URL` here is the LOCAL address, port and
 *   all. It is the single most damaging value that could travel — an application
 *   in production generating links to somebody's laptop.
 * - **`source`.** Running from a working copy is the one thing about this
 *   platform that is local by construction. There is no disk to mount there.
 * - **Secrets.** They are not in the manifest to begin with, which is the point
 *   of bindings: a database password is a `secretKeyRef` resolved on each side,
 *   and it has never been in a file anybody could push.
 * - **Resources.** A production database is a decision with a size, a backup
 *   policy and a bill attached. The manifest says the application needs
 *   Postgres; it does not get to provision one by being pushed.
 * - **`suspended`.** Whether something is asleep on a laptop this afternoon.
 */
readonly class PushPayload
{
    public function __construct(private ProjectManifest $manifest) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $domain = ''): array
    {
        $payload = [
            'name' => $this->manifest->name,
            'image' => $this->manifest->image,
            'port' => $this->manifest->port,
            'replicas' => $this->manifest->replicas,
            'env' => $this->environment(),
            'scale_to_zero' => $this->manifest->scaleToZero,
            'idle_timeout_seconds' => $this->manifest->idleSeconds,
        ];

        if ($domain !== '') {
            $payload['domain'] = $domain;
        }

        return $payload;
    }

    /**
     * The declared environment, with the local address taken back out.
     *
     * `withResolvedUrl()` put this machine's own hostname in, and it is exactly
     * the value that must not reach production. Removed rather than never
     * added, because the same manifest object is what gets deployed locally and
     * what gets pushed, and having two shapes of it would mean the two could
     * disagree.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $env = $this->manifest->env;

        if ($this->manifest->urlVariable !== '') {
            unset($env[$this->manifest->urlVariable]);
        }

        return $env;
    }

    /**
     * Everything this deliberately left behind, in the developer's words.
     *
     * SHOWN, NOT SILENT. A push that quietly drops half a manifest is a push
     * somebody will believe carried all of it, and they will find out when
     * their production service answers on no hostname.
     *
     * @return list<string>
     */
    public function omitted(): array
    {
        $omitted = [];

        if ($this->manifest->domains !== []) {
            $omitted[] = count($this->manifest->domains).' local hostname'
                .(count($this->manifest->domains) === 1 ? '' : 's')
                .' — production hostnames belong to Cortex, not to this file';
        }

        if ($this->manifest->urlVariable !== '') {
            $omitted[] = $this->manifest->urlVariable.' — it holds this machine\'s address';
        }

        if ($this->manifest->fromSource) {
            $omitted[] = 'source — there is no working copy to mount in production';
        }

        if ($this->manifest->resources !== []) {
            $omitted[] = count($this->manifest->resources).' resource'
                .(count($this->manifest->resources) === 1 ? '' : 's')
                .' — a production database is provisioned deliberately, not by being pushed';
        }

        if ($this->manifest->processes !== []) {
            // Honest about a real gap rather than pretending: processes are a
            // separate call on the API, and this does not make it yet.
            $omitted[] = count($this->manifest->processes).' process'
                .(count($this->manifest->processes) === 1 ? '' : 'es')
                .' — not pushed yet; add them in Cortex';
        }

        return $omitted;
    }
}
