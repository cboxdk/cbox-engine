<?php

declare(strict_types=1);

namespace Cbox\Engine\ValueObjects;

use Cbox\Engine\Enums\Severity;

/**
 * One thing the doctor checked, and what to do about it.
 *
 * `remedy` is not optional decoration. A developer tool that reports a condition
 * without an action has handed its user a research project, and the person who
 * can fix it is standing right there. Every finding that is not `Ok` carries the
 * sentence that ends it.
 */
readonly class Finding
{
    public function __construct(
        public string $subject,
        public Severity $severity,
        public string $detail,
        public string $remedy = '',
    ) {}

    public static function ok(string $subject, string $detail): self
    {
        return new self($subject, Severity::Ok, $detail);
    }

    public static function warning(string $subject, string $detail, string $remedy): self
    {
        return new self($subject, Severity::Warning, $detail, $remedy);
    }

    public static function blocked(string $subject, string $detail, string $remedy): self
    {
        return new self($subject, Severity::Blocked, $detail, $remedy);
    }

    /**
     * @return array{subject: string, severity: string, detail: string, remedy: string}
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'severity' => $this->severity->value,
            'detail' => $this->detail,
            'remedy' => $this->remedy,
        ];
    }
}
