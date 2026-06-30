<?php

declare(strict_types=1);

namespace common\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * ActiveRecord over kf_ad_group — backend preview only (§15.7).
 *
 * @property int $id
 * @property int $project_id
 * @property string $group_name
 * @property string|null $intent_class
 * @property string|null $language
 * @property string|null $target_url
 * @property-read ResponsiveSearchAd|null $responsiveSearchAd
 */
final class AdGroup extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'kf_ad_group';
    }

    public function getResponsiveSearchAd(): ActiveQuery
    {
        return $this->hasOne(ResponsiveSearchAd::class, ['ad_group_id' => 'id']);
    }
}
