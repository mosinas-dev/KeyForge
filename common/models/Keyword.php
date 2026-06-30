<?php

declare(strict_types=1);

namespace common\models;

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
}
