<?php

declare(strict_types=1);

namespace common\pipeline;

/**
 * State carried through the linear pipeline (ADR 0005). Stages operate set-based
 * on kf_keyword rows scoped by project_id; the context carries the tenant scope,
 * the current import batch, and per-stage in/out counts (§2 logging).
 */
final class PipelineContext
{
    public int $projectId;
    public ?int $importBatchId = null;

    /** @var array<string,array{in:int,out:int}> */
    private array $stageStats = [];

    public function __construct(int $projectId)
    {
        $this->projectId = $projectId;
    }

    /** Record how many rows a stage saw (in) and produced/kept (out). */
    public function recordStage(string $stage, int $in, int $out): void
    {
        $this->stageStats[$stage] = ['in' => $in, 'out' => $out];
    }

    /** @return array<string,array{in:int,out:int}> */
    public function stageStats(): array
    {
        return $this->stageStats;
    }
}
