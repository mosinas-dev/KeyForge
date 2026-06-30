<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use console\controllers\KeyforgeController;
use yii\console\ExitCode;
use Yii;

/**
 * Phase 2 gate (§9): `yii keyforge/import <file.csv>` imports a real sample source
 * into kf_keyword and is idempotent on re-import.
 */
class KeyforgeImportCommandTest extends Unit
{
    private function sample(string $name): string
    {
        return dirname(__DIR__, 3) . '/sample_data/' . $name;
    }

    private function controller(): KeyforgeController
    {
        // Built via the DI container so injected services/ports are resolved.
        return Yii::createObject(KeyforgeController::class, ['keyforge', Yii::$app]);
    }

    private function keywordCount(string $sourceType): int
    {
        return (int) Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM kf_keyword WHERE source_type = :s', [':s' => $sourceType]
        )->queryScalar();
    }

    public function testImportsSampleCsvThenReimportAddsZero(): void
    {
        $controller = $this->controller();

        $exit = $controller->actionImport($this->sample('ahrefs_paid_keywords.csv'));
        $this->assertSame(ExitCode::OK, $exit);

        $afterFirst = $this->keywordCount('ahrefs_paid');
        $this->assertGreaterThan(0, $afterFirst, 'sample rows must land in kf_keyword');

        $exitAgain = $controller->actionImport($this->sample('ahrefs_paid_keywords.csv'));
        $this->assertSame(ExitCode::OK, $exitAgain);
        $this->assertSame($afterFirst, $this->keywordCount('ahrefs_paid'), 're-import of same file = 0 new (§9)');
    }

    public function testUnknownFileReturnsErrorExitCode(): void
    {
        $this->assertSame(
            ExitCode::DATAERR,
            $this->controller()->actionImport('/nonexistent/unknown_source.csv')
        );
    }
}
