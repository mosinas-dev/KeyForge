<?php

declare(strict_types=1);

namespace backend\controllers;

use backend\models\UploadForm;
use common\models\AdGroup;
use common\models\Keyword;
use common\services\KeywordPipelineService;
use common\sources\CsvSourceCatalog;
use common\sources\KeywordSourceFactory;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\FileHelper;
use yii\web\Controller;
use yii\web\Response;
use yii\web\UploadedFile;
use ZipArchive;

/**
 * KeyForge admin (§5 / Phase 7): upload sources, review keywords, preview campaigns,
 * export. Thin controller (§15.5) — validation in UploadForm, orchestration in
 * KeywordPipelineService. Access is RBAC-gated per action (seeded admin/marketer).
 */
final class KeyforgeController extends Controller
{
    private const PROJECT_ID = 1;

    public function __construct($id, $module, private KeywordPipelineService $pipeline, array $config = [])
    {
        parent::__construct($id, $module, $config);
    }

    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['actions' => ['index'], 'allow' => true, 'roles' => ['@']],
                    ['actions' => ['upload'], 'allow' => true, 'roles' => ['importKeywords']],
                    ['actions' => ['keywords'], 'allow' => true, 'roles' => ['reviewKeywords']],
                    ['actions' => ['preview'], 'allow' => true, 'roles' => ['previewCampaigns']],
                    ['actions' => ['prepare', 'export'], 'allow' => true, 'roles' => ['exportCampaigns']],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['export' => ['post'], 'prepare' => ['post']],
            ],
        ];
    }

    public function actionIndex(): string
    {
        return $this->render('index', [
            'keywordCount' => (int) Keyword::find()->where(['project_id' => self::PROJECT_ID])->count(),
            'activeCount' => (int) Keyword::find()->where(['project_id' => self::PROJECT_ID, 'status' => 'new'])->count(),
            'groupCount' => (int) AdGroup::find()->where(['project_id' => self::PROJECT_ID])->count(),
        ]);
    }

    public function actionUpload(): string|Response
    {
        $form = new UploadForm();
        if (Yii::$app->request->isPost) {
            $form->file = UploadedFile::getInstance($form, 'file');
            $form->load(Yii::$app->request->post());
            if ($form->validate()) {
                $context = $this->import($form);
                $ingest = $context->stageStats()['ingest'];
                Yii::$app->session->setFlash(
                    'success',
                    "Imported {$form->sourceType}: {$ingest['out']} new / {$ingest['in']} rows. Cleaning pipeline applied."
                );

                return $this->redirect(['keywords']);
            }
        }

        return $this->render('upload', ['form' => $form, 'sourceTypes' => CsvSourceCatalog::sourceTypes()]);
    }

    public function actionKeywords(): string
    {
        $query = Keyword::find()->where(['project_id' => self::PROJECT_ID]);
        foreach (['status', 'detected_language', 'intent_class'] as $filter) {
            $value = Yii::$app->request->get($filter);
            if ($value !== null && $value !== '') {
                $query->andWhere([$filter => $value]);
            }
        }

        return $this->render('keywords', [
            'dataProvider' => new ActiveDataProvider([
                'query' => $query,
                'sort' => ['defaultOrder' => ['search_volume' => SORT_DESC]],
                'pagination' => ['pageSize' => 50],
            ]),
        ]);
    }

    public function actionPreview(): string
    {
        return $this->render('preview', [
            'groups' => AdGroup::find()
                ->where(['project_id' => self::PROJECT_ID])
                ->with('responsiveSearchAd')
                ->orderBy(['language' => SORT_ASC])
                ->all(),
        ]);
    }

    /** Run GAds-prep + RSA generation, then return to preview. */
    public function actionPrepare(): Response
    {
        $context = $this->pipeline->prepareCampaigns(self::PROJECT_ID);
        $ads = $context->stageStats()['ad_generation'];
        Yii::$app->session->setFlash('success', "Prepared {$ads['in']} group(s), {$ads['out']} with a valid ad.");

        return $this->redirect(['preview']);
    }

    /** Build the Google Ads Editor files and stream them as a zip download. */
    public function actionExport(): Response
    {
        $result = $this->pipeline->export(self::PROJECT_ID);

        $zipPath = Yii::getAlias('@runtime') . '/keyforge_export_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($result->files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        // NB: Component::on() returns void — attach the cleanup separately and return
        // the Response itself (chaining on() would return null and break the : Response type).
        $response = Yii::$app->response->sendFile($zipPath, 'keyforge_export.zip', ['mimeType' => 'application/zip']);
        $response->on(Response::EVENT_AFTER_SEND, static fn () => @unlink($zipPath));

        return $response;
    }

    private function import(UploadForm $form): \common\pipeline\PipelineContext
    {
        $uploadDir = Yii::getAlias('@runtime/uploads');
        FileHelper::createDirectory($uploadDir);
        $extension = strtolower((string) $form->file->extension);
        $savedPath = $uploadDir . '/' . bin2hex(random_bytes(6)) . '.' . $extension;
        $form->file->saveAs($savedPath);

        $source = KeywordSourceFactory::build($savedPath, $form->sourceType);

        return $this->pipeline->importSource(self::PROJECT_ID, $source, $form->file->baseName . '.' . $extension);
    }
}
