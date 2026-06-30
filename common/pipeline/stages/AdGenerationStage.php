<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\adgen\AdCopy;
use common\adgen\AdCopyGenerator;
use common\adgen\AdCopyRequest;
use common\adgen\RsaLengthValidator;
use common\pipeline\KeywordStatus;
use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\services\LanguageDetector;
use Throwable;
use yii\db\Connection;

/**
 * RSA generation (§2.9). For each ad group: build a request from its eligible
 * keywords + pinned brand headline, ask the generator, then DETERMINISTICALLY
 * validate length (RsaLengthValidator) and language (LanguageDetector). Invalid or
 * broken output is regenerated up to MAX_ATTEMPTS; if it never validates the RSA is
 * stored with validation_status='failed' (never crashes, §11). One RSA per group
 * (replace on re-run -> idempotent).
 */
final class AdGenerationStage implements PipelineStage
{
    private const MAX_ATTEMPTS = 3;
    private const KEYWORDS_PER_GROUP = 10;

    public function __construct(
        private Connection $db,
        private AdCopyGenerator $generator,
        private RsaLengthValidator $validator,
        private LanguageDetector $detector,
    ) {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $brandHeadline = $this->db->createCommand(
            'SELECT name FROM kf_project WHERE id = :p', [':p' => $context->projectId]
        )->queryScalar() ?: null;

        $groups = $this->db->createCommand(
            'SELECT id, intent_class, language, target_url FROM kf_ad_group WHERE project_id = :p',
            [':p' => $context->projectId]
        )->queryAll();

        $valid = 0;
        foreach ($groups as $group) {
            $request = new AdCopyRequest(
                (string) $group['language'],
                (string) $group['target_url'],
                $this->groupKeywords($context->projectId, $group),
                $brandHeadline === null ? null : (string) $brandHeadline
            );
            [$copy, $status] = $this->generateValid($request, (string) $group['language']);
            $this->persist((int) $group['id'], $copy, $status);
            if ($status === 'valid') {
                $valid++;
            }
        }

        $context->recordStage('ad_generation', count($groups), $valid);

        return $context;
    }

    /** @return string[] */
    private function groupKeywords(int $projectId, array $group): array
    {
        return $this->db->createCommand(
            'SELECT normalized_keyword FROM kf_keyword
             WHERE project_id = :p AND status = :s AND intent_class = :i AND detected_language = :l
               AND is_brand = false AND is_forbidden = false
             ORDER BY search_volume DESC NULLS LAST, id ASC
             LIMIT ' . self::KEYWORDS_PER_GROUP,
            [':p' => $projectId, ':s' => KeywordStatus::NEW, ':i' => $group['intent_class'], ':l' => $group['language']]
        )->queryColumn();
    }

    /**
     * @return array{0:AdCopy,1:string} [copy, 'valid'|'failed']
     */
    private function generateValid(AdCopyRequest $request, string $language): array
    {
        $lastCopy = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $copy = $this->generator->generate($request);
            } catch (Throwable $exception) {
                continue; // broken response (e.g. invalid JSON) -> regenerate, don't crash
            }
            $lastCopy = $copy;
            if ($this->isAcceptable($copy, $language)) {
                return [$copy, 'valid'];
            }
        }

        return [$lastCopy ?? new AdCopy([], []), 'failed'];
    }

    private function isAcceptable(AdCopy $copy, string $language): bool
    {
        if (!$this->validator->isValid($copy)) {
            return false;
        }
        $combined = implode(' ', array_merge($copy->headlineTexts(), $copy->descriptionTexts()));
        $detected = $this->detector->detect($combined);

        // null = couldn't tell (short/ambiguous) -> accept; a different language -> reject.
        return $detected === null || $detected === $language;
    }

    private function persist(int $adGroupId, AdCopy $copy, string $status): void
    {
        $this->db->createCommand(
            'DELETE FROM kf_responsive_search_ad WHERE ad_group_id = :g', [':g' => $adGroupId]
        )->execute();

        $this->db->createCommand(
            'INSERT INTO kf_responsive_search_ad (ad_group_id, headlines, descriptions, validation_status)
             VALUES (:g, CAST(:h AS jsonb), CAST(:d AS jsonb), :s)',
            [
                ':g' => $adGroupId,
                ':h' => json_encode($copy->headlines, JSON_UNESCAPED_UNICODE),
                ':d' => json_encode($copy->descriptions, JSON_UNESCAPED_UNICODE),
                ':s' => $status,
            ]
        )->execute();
    }
}
