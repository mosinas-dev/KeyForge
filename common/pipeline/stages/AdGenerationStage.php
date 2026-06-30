<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\adgen\AdCopy;
use common\adgen\AdCopyGenerator;
use common\adgen\AdCopyRequest;
use common\adgen\RsaLengthValidator;
use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\repositories\AdGroupRepositoryInterface;
use common\repositories\ConfigRepositoryInterface;
use common\repositories\KeywordRepositoryInterface;
use common\services\LanguageDetector;
use Throwable;

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

    public function __construct(
        private KeywordRepositoryInterface $keywords,
        private AdGroupRepositoryInterface $adGroups,
        private ConfigRepositoryInterface $config,
        private AdCopyGenerator $generator,
        private RsaLengthValidator $validator,
        private LanguageDetector $detector,
    ) {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $brandHeadline = $this->config->projectName($context->projectId);
        $groups = $this->adGroups->allGroups($context->projectId);

        $valid = 0;
        foreach ($groups as $group) {
            $request = new AdCopyRequest(
                $group['language'],
                $group['target_url'],
                $this->keywords->eligibleKeywords($context->projectId, $group['intent_class'], $group['language']),
                $brandHeadline
            );
            [$copy, $status] = $this->generateValid($request, $group['language']);
            $this->adGroups->replaceRsa($group['id'], $copy->headlines, $copy->descriptions, $status);
            if ($status === 'valid') {
                $valid++;
            }
        }

        $context->recordStage('ad_generation', count($groups), $valid);

        return $context;
    }

    /** @return array{0:AdCopy,1:string} [copy, 'valid'|'failed'] */
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
        if (!$this->validator->validate($copy)->isValid()) {
            return false;
        }
        $combined = implode(' ', array_merge($copy->headlineTexts(), $copy->descriptionTexts()));
        $detected = $this->detector->detect($combined);

        // null = couldn't tell (short/ambiguous) -> accept; a different language -> reject.
        return $detected === null || $detected === $language;
    }
}
