<?php

declare(strict_types=1);

use yii\grid\GridView;
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = 'Ключи';
?>
<div class="keyforge-keywords">
    <h1><?= Html::encode($this->title) ?></h1>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'columns' => [
            'raw_keyword',
            'normalized_keyword',
            'detected_language',
            'intent_class',
            'status',
            'search_volume',
            ['attribute' => 'is_brand', 'format' => 'boolean'],
            ['attribute' => 'is_opportunity', 'format' => 'boolean'],
        ],
    ]) ?>
</div>
