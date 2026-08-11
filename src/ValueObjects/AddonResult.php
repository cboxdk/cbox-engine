<?php

declare(strict_types=1);

namespace Cbox\Engine\ValueObjects;

/** What happened to one addon. */
readonly class AddonResult
{
    public function __construct(
        public string $name,
        public bool $succeeded,
        public int $objects,
        public string $failure = '',
    ) {}

    /**
     * @return array{name: string, succeeded: bool, objects: int, failure: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'succeeded' => $this->succeeded,
            'objects' => $this->objects,
            'failure' => $this->failure,
        ];
    }
}
