<?php

declare(strict_types=1);

namespace common\adgen;

/**
 * Result of RSA validation (§14.15): carries the violations instead of a bare bool.
 * Empty violations == valid.
 */
final readonly class RsaValidationResult
{
    /** @param string[] $violations human-readable problems; empty means valid */
    public function __construct(public array $violations)
    {
    }

    public function isValid(): bool
    {
        return $this->violations === [];
    }
}
