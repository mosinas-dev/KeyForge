<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\Keyforge;

/**
 * Phase 0 smoke test (DB-less): proves the app autoloads and the committed
 * runtime config targets PostgreSQL. No database connection is opened here —
 * this is the test the Docker build-stage gate runs without Postgres.
 */
class BootstrapSmokeTest extends Unit
{
    public function testYiiFrameworkIsBootstrapped(): void
    {
        $this->assertNotEmpty(\Yii::getVersion(), 'Yii framework must be autoloaded');
    }

    public function testKeyforgeMarkerIsAvailable(): void
    {
        $this->assertSame('KeyForge', Keyforge::NAME);
    }

    public function testCommittedDbConfigTargetsPostgres(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/main-local.php';
        $dsn = $config['components']['db']['dsn'] ?? '';
        $this->assertStringStartsWith('pgsql:', $dsn, 'KeyForge runs on PostgreSQL only (ADR 0002)');
    }
}
