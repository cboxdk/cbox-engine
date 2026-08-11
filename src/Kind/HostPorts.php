<?php

declare(strict_types=1);

namespace Cbox\Engine\Kind;

/**
 * Which ports on this machine the cluster answers on.
 *
 * THE REAL ONES IF THEY ARE FREE. A development environment reached at
 * `https://demo.cbox.test:18443` is not the environment anybody's application
 * will run in: OAuth redirect URIs, cookie domains, CORS origins, `APP_URL` and
 * every link in an email all carry the port, and a developer spends the day
 * translating. 80 and 443 are the whole point of a hostname.
 *
 * NO PRIVILEGE IS NEEDED, which was the assumption that kept this on 18080 for
 * so long. Docker's daemon binds the port, not this process — measured on this
 * machine: a container published on `127.0.0.1:80` starts with no prompt and no
 * sudo.
 *
 * FALLING BACK RATHER THAN FIGHTING. Herd, another cluster, or anything else may
 * already hold 80 — so this looks first and takes the high ports if it must,
 * which is the difference between coexisting and demanding to be the only thing
 * installed. What was actually chosen is read back off the running container, so
 * nothing here has to remember.
 */
readonly class HostPorts
{
    public const PRIVILEGED_HTTP = 80;

    public const PRIVILEGED_HTTPS = 443;

    public const PRIVILEGED_DNS = 53;

    public const HIGH_HTTP = 18080;

    public const HIGH_HTTPS = 18443;

    public const HIGH_DNS = 15353;

    public function __construct(
        public int $http,
        public int $https,
        public int $dns,
    ) {}

    /**
     * What this machine can offer right now.
     *
     * ALL THREE OR NONE. A cluster on 80 and 443 whose DNS landed on 15353 works
     * and is confusing to explain, and the resolver file would have to say
     * something different depending on the day it was written. One decision.
     *
     * @param  (callable(int): bool)|null  $free  whether a port can be had; the
     *                                            real machine when nothing is
     *                                            passed, and a test needs to be
     *                                            able to ask both questions
     *                                            without owning port 443
     */
    public static function preferred(?callable $free = null): self
    {
        $free ??= self::free(...);

        $available = $free(self::PRIVILEGED_HTTP)
            && $free(self::PRIVILEGED_HTTPS)
            && $free(self::PRIVILEGED_DNS);

        return $available ? self::privileged() : self::high();
    }

    public static function privileged(): self
    {
        return new self(self::PRIVILEGED_HTTP, self::PRIVILEGED_HTTPS, self::PRIVILEGED_DNS);
    }

    public static function high(): self
    {
        return new self(self::HIGH_HTTP, self::HIGH_HTTPS, self::HIGH_DNS);
    }

    public function isPrivileged(): bool
    {
        return $this->https === self::PRIVILEGED_HTTPS;
    }

    /** A hostname as somebody would type it, with the port only when it is needed. */
    public function url(string $hostname): string
    {
        return $this->isPrivileged()
            ? 'https://'.$hostname
            : 'https://'.$hostname.':'.$this->https;
    }

    /**
     * Whether anything already holds that port on loopback.
     *
     * Asked by CONNECTING, not by binding. This process cannot bind a privileged
     * port at all — measured: `Permission denied` on 80, 443 and 53 — so a bind
     * probe would answer "taken" every time and this would never once choose the
     * ports it exists to choose. Docker's daemon does the binding, and the only
     * thing that stops it is somebody else already listening.
     *
     * A refused connection is a free port. A short timeout, because a loopback
     * connection either happens immediately or is not happening.
     */
    private static function free(int $port): bool
    {
        $socket = @stream_socket_client(
            'tcp://127.0.0.1:'.$port,
            $code,
            $message,
            0.3,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            return true;
        }

        fclose($socket);

        return false;
    }
}
