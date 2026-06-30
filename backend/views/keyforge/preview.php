<?php

declare(strict_types=1);

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var common\models\AdGroup[] $groups */

$this->title = 'Preview кампаний';
?>
<div class="keyforge-preview">
    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::beginForm(['prepare'], 'post')
            . Html::submitButton('Подготовить кампании (prepare-gads)', ['class' => 'btn btn-secondary'])
            . Html::endForm() ?>
        <?= Html::beginForm(['export'], 'post')
            . Html::submitButton('Экспорт в Google Ads Editor (zip)', ['class' => 'btn btn-primary'])
            . Html::endForm() ?>
    </p>

    <?php if ($groups === []): ?>
        <p><em>Групп пока нет. Импортируйте источники и нажмите «Подготовить кампании».</em></p>
    <?php endif; ?>

    <?php foreach ($groups as $group): ?>
        <?php $rsa = $group->responsiveSearchAd; ?>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">
                    <?= Html::encode($group->group_name) ?>
                    <small class="text-muted"><?= Html::encode((string) $group->language) ?> · <?= Html::encode((string) $group->target_url) ?></small>
                </h5>
                <?php if ($rsa !== null): ?>
                    <p class="mb-1"><b>Заголовки:</b> <?= Html::encode(implode('  |  ', $rsa->headlineTexts)) ?></p>
                    <p class="mb-1"><b>Описания:</b> <?= Html::encode(implode('  |  ', $rsa->descriptionTexts)) ?></p>
                    <span class="badge bg-<?= $rsa->validation_status === 'valid' ? 'success' : 'warning' ?>">
                        <?= Html::encode($rsa->validation_status) ?>
                    </span>
                <?php else: ?>
                    <em>Нет объявления — нажмите «Подготовить кампании».</em>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
