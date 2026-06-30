<?php

declare(strict_types=1);

namespace common\repositories;

/** Repository for kf_negative_keyword (junk minus-words, §2.2 / §15). */
interface NegativeKeywordRepositoryInterface
{
    /** Add a negative keyword, ignoring duplicates (UNIQUE(project_id, keyword_text)). */
    public function addIgnoringDuplicate(int $projectId, string $keywordText, string $reason, ?string $language): void;

    /** @return string[] all negative keyword texts of the project */
    public function allTexts(int $projectId): array;
}
