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
    <p class="text-muted">CSV-источник (ahrefs/google_ads/search_console). После импорта прогоняется чистка.</p>

    <?php $f = ActiveForm::begin(['options' => ['enctype' => 'multipart/form-data']]); ?>
        <?= $f->field($form, 'sourceType')->dropDownList(array_combine($sourceTypes, $sourceTypes)) ?>
        <?= $f->field($form, 'file')->fileInput() ?>
        <div class="form-group">
            <?= Html::submitButton('Импортировать', ['class' => 'btn btn-primary']) ?>
        </div>
    <?php ActiveForm::end(); ?>
</div>
