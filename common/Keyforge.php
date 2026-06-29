<?php

declare(strict_types=1);

namespace common;

/**
 * KeyForge application marker — single source of truth for the product name and
 * version. Referenced by the Phase 0 bootstrap smoke test; later surfaced in the
 * admin footer / healthcheck.
 */
final class Keyforge
{
    public const NAME = 'KeyForge';
    public const VERSION = '0.1.0-dev';
}
