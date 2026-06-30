<?php

declare(strict_types=1);

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var int $total */
/** @var int $active */
/** @var int $used */
/** @var int $brand */
/** @var int $forbidden */
/** @var int $junk */
/** @var int $merged */
/** @var int $lowVolume */
/** @var int $groupCount */

$this->title = 'KeyForge';
?>
<div class="keyforge-index">
    <h1><?= Html::encode($this->title) ?></h1>
    <p class="lead">Всего ключей: <b><?= $total ?></b> · STAG-групп: <b><?= $groupCount ?></b></p>

    <table class="table table-sm align-middle" style="max-width: 560px">
        <thead><tr><th>Категория</th><th class="text-end">Ключей</th><th></th></tr></thead>
        <tbody>
            <tr class="table-success">
                <td>Активные (идут в кампании)</td>
                <td class="text-end"><b><?= $active ?></b></td>
                <td><?= Html::a('показать', ['keywords', 'status' => 'new']) ?></td>
            </tr>
            <tr>
                <td>🟦 Уже используются (google_ads)</td>
                <td class="text-end"><?= $used ?></td>
                <td><?= Html::a('показать', ['keywords', 'status' => 'used']) ?></td>
            </tr>
            <tr>
                <td>🟪 Бренд (отфильтрованы)</td>
                <td class="text-end"><?= $brand ?></td>
                <td><?= Html::a('показать', ['keywords', 'is_brand' => 1]) ?></td>
            </tr>
            <tr>
                <td>⛔ Forbidden</td>
                <td class="text-end"><?= $forbidden ?></td>
                <td><?= Html::a('показать', ['keywords', 'is_forbidden' => 1]) ?></td>
            </tr>
            <tr>
                <td>🗑 Junk → минус-слова</td>
                <td class="text-end"><?= $junk ?></td>
                <td><?= Html::a('показать', ['keywords', 'status' => 'junk']) ?></td>
            </tr>
            <tr>
                <td>🔁 Дубли (merged)</td>
                <td class="text-end"><?= $merged ?></td>
                <td><?= Html::a('показать', ['keywords', 'status' => 'merged']) ?></td>
            </tr>
            <tr>
                <td>📉 Низкий объём</td>
                <td class="text-end"><?= $lowVolume ?></td>
                <td><?= Html::a('показать', ['keywords', 'status' => 'low_volume']) ?></td>
            </tr>
        </tbody>
    </table>

    <p>
        <?= Html::a('Загрузить источник (CSV/JSON)', ['upload'], ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Все ключи', ['keywords'], ['class' => 'btn btn-outline-secondary']) ?>
        <?= Html::a('Preview кампаний', ['preview'], ['class' => 'btn btn-outline-secondary']) ?>
    </p>
</div>
