<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\pipeline\PipelineContext;
use common\pipeline\PipelineRunner;
use common\pipeline\PipelineStage;

/**
 * Phase 3: linear runner (ADR 0005, NOT a DAG). DB-less — stages are stubs.
 */
class PipelineRunnerTest extends Unit
{
    private function stage(string $name): PipelineStage
    {
        return new class ($name) implements PipelineStage {
            public function __construct(private string $name)
            {
            }

            public function run(PipelineContext $context): PipelineContext
            {
                $context->recordStage($this->name, 0, 0);

                return $context;
            }
        };
    }

    public function testRunsStagesInOrderAndThreadsTheContext(): void
    {
        $context = new PipelineContext(1);
        $result = (new PipelineRunner([$this->stage('a'), $this->stage('b'), $this->stage('c')]))->run($context);

        $this->assertSame($context, $result, 'runner returns the threaded context');
        $this->assertSame(['a', 'b', 'c'], array_keys($result->stageStats()), 'stages run in given order');
    }

    public function testEmptyPipelineReturnsContextUnchanged(): void
    {
        $context = new PipelineContext(7);
        $result = (new PipelineRunner([]))->run($context);

        $this->assertSame($context, $result);
        $this->assertSame([], $result->stageStats());
    }

    public function testUsesContextReturnedByEachStage(): void
    {
        $replacement = new PipelineContext(99);
        $swapStage = new class ($replacement) implements PipelineStage {
            public function __construct(private PipelineContext $replacement)
            {
            }

            public function run(PipelineContext $context): PipelineContext
            {
                return $this->replacement; // a stage may return a different context
            }
        };

        $result = (new PipelineRunner([$swapStage]))->run(new PipelineContext(1));
        $this->assertSame($replacement, $result);
        $this->assertSame(99, $result->projectId);
    }
}
