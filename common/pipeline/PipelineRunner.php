<?php

declare(strict_types=1);

namespace common\pipeline;

/**
 * Runs an ordered list of stages linearly (ADR 0005 — NOT a DAG; the batch
 * pipeline doesn't need one, §13). Depends on the PipelineStage interface, not on
 * concrete stages (DIP, §12). Each stage's returned context feeds the next.
 */
final class PipelineRunner
{
    /** @var PipelineStage[] */
    private array $stages;

    /** @param PipelineStage[] $stages */
    public function __construct(array $stages)
    {
        $this->stages = $stages;
    }

    public function run(PipelineContext $context): PipelineContext
    {
        foreach ($this->stages as $stage) {
            $context = $stage->run($context);
        }

        return $context;
    }
}
