<?php

declare(strict_types=1);

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/** @var yii\web\View $this */
/** @var backend\models\UploadForm $form */
/** @var string[] $sourceTypes */

$this->title = 'Загрузка источника';
?>
<div class="keyforge-upload">
    <h1><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">Источник <b>CSV или JSON</b> (ahrefs / google_ads / search_console). После импорта автоматически прогоняется чистка (junk → минус-слова, дедуп, бренды, язык, объём).</p>

    <?php $f = ActiveForm::begin(['options' => ['enctype' => 'multipart/form-data']]); ?>
        <?= $f->field($form, 'sourceType')->dropDownList(array_combine($sourceTypes, $sourceTypes)) ?>
        <?= $f->field($form, 'file')->fileInput(['accept' => '.csv,.json']) ?>
        <div class="form-group">
            <?= Html::submitButton('Импортировать', ['class' => 'btn btn-primary']) ?>
        </div>
    <?php ActiveForm::end(); ?>
</div>
