<?php

declare(strict_types=1);

namespace common\models;

use common\pipeline\KeywordStatus;
use yii\db\ActiveRecord;

/**
 * ActiveRecord over kf_keyword — used ONLY by the backend admin for review grids
 * (infrastructure mapping, §15.7). Pipeline business logic goes through
 * KeywordRepositoryInterface, never this model.
 *
 * @property int $id
 * @property int $project_id
 * @property string $source_type
 * @property string $raw_keyword
 * @property string $normalized_keyword
 * @property int|null $search_volume
 * @property string|null $detected_language
 * @property string|null $intent_class
 * @property bool $is_brand
 * @property bool $is_forbidden
 * @property bool $is_opportunity
 * @property int|null $merged_into_keyword_id
 * @property string $status
 */
final class Keyword extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'kf_keyword';
    }

    /**
     * Human-readable reason this keyword is excluded from generated campaigns, for
     * the admin review grid; empty string = active/eligible. Terminal statuses win,
     * then the brand/forbidden flags (a brand or already-used keyword is filtered
     * out of Ads — §9).
     */
    public function exclusionReason(): string
    {
        return match (true) {
            $this->status === KeywordStatus::Junk->value => 'junk → negative',
            $this->status === KeywordStatus::Merged->value => 'merged (duplicate)',
            $this->status === KeywordStatus::LowVolume->value => 'low volume',
            $this->status === KeywordStatus::Used->value => 'already used',
            (bool) $this->is_brand => 'brand',
            (bool) $this->is_forbidden => 'forbidden',
            default => '',
        };
    }

    public function isActive(): bool
    {
        return $this->exclusionReason() === '';
    }
}

