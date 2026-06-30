<?php

declare(strict_types=1);

namespace common\models;

use yii\db\ActiveRecord;

/**
 * ActiveRecord over kf_responsive_search_ad — backend preview only (§15.7).
 * headlines/descriptions are jsonb arrays of {text, pin}.
 *
 * @property int $id
 * @property int $ad_group_id
 * @property array $headlines
 * @property array $descriptions
 * @property string $validation_status
 */
final class ResponsiveSearchAd extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'kf_responsive_search_ad';
    }

    /** jsonb columns come back as JSON strings; decode to arrays of {text, pin}. */
    public function getHeadlineTexts(): array
    {
        return $this->extractTexts($this->headlines);
    }

    public function getDescriptionTexts(): array
    {
        return $this->extractTexts($this->descriptions);
    }

    private function extractTexts(mixed $jsonb): array
    {
        $items = is_array($jsonb) ? $jsonb : (json_decode((string) $jsonb, true) ?: []);

        return array_map(static fn (array $item): string => (string) ($item['text'] ?? ''), $items);
    }
}
