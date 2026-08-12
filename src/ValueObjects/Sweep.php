<?php

declare(strict_types=1);

namespace Cbox\Engine\ValueObjects;

/**
 * What a deploy took away, and what it declined to.
 *
 * TWO LISTS RATHER THAN ONE, because they need different sentences. Something
 * removed is housekeeping the person does not have to think about; something
 * retained is a database still running that their manifest no longer mentions,
 * and saying nothing about it is how a machine ends up with volumes nobody can
 * account for.
 */
readonly class Sweep
{
    /**
     * @param  list<string>  $removed  `Kind/name` of everything deleted
     * @param  list<string>  $retained  `Kind/name` of the data-bearing objects left alone
     */
    public function __construct(
        public array $removed = [],
        public array $retained = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->removed === [] && $this->retained === [];
    }
}
