<?php

declare(strict_types=1);

namespace common\pipeline;

/**
 * One cleaning/preparation step (§2). The runner depends on this interface, not on
 * concrete stages (DIP, §12); a new stage is a new class, no edits to the runner
 * (OCP). Each stage mutates and returns the context.
 */
interface PipelineStage
{
    public function run(PipelineContext $context): PipelineContext;
}
