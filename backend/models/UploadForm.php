<?php

declare(strict_types=1);

namespace backend\models;

use common\sources\CsvSourceCatalog;
use yii\base\Model;
use yii\web\UploadedFile;

/**
 * Backend upload form (§5): a CSV file + its source type. Validation lives here,
 * not in the controller (§15.13). The controller turns a valid form into a
 * CsvSource and hands it to KeywordPipelineService.
 */
final class UploadForm extends Model
{
    public ?UploadedFile $file = null;
    public string $sourceType = 'ahrefs_organic';

    public function rules(): array
    {
        return [
            [['file'], 'required'],
            [['file'], 'file', 'extensions' => 'csv, json', 'checkExtensionByMimeType' => false, 'maxSize' => 32 * 1024 * 1024],
            [['sourceType'], 'required'],
            [['sourceType'], 'in', 'range' => CsvSourceCatalog::sourceTypes()],
        ];
    }

    public function attributeLabels(): array
    {
        return ['file' => 'CSV file', 'sourceType' => 'Source type'];
    }
}
