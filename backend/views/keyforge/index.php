<?php

declare(strict_types=1);

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var int $keywordCount */
/** @var int $activeCount */
/** @var int $groupCount */

$this->title = 'KeyForge';
?>
<div class="keyforge-index">
    <h1><?= Html::encode($this->title) ?></h1>
    <p class="lead">
        Ключей: <b><?= $keywordCount ?></b> ·
        активных: <b><?= $activeCount ?></b> ·
        групп: <b><?= $groupCount ?></b>
    </p>
    <p>
        <?= Html::a('Загрузить источник', ['upload'], ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Ключи', ['keywords'], ['class' => 'btn btn-outline-secondary']) ?>
        <?= Html::a('Preview кампаний', ['preview'], ['class' => 'btn btn-outline-secondary']) ?>
    </p>
</div>
