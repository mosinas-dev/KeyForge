<?php

declare(strict_types=1);

namespace common\adgen;

/**
 * Port for RSA ad-copy generation (§2.9, ISP/DIP). AdGenerationStage depends on this
 * interface, not on a concrete generator. A deterministic template generator backs
 * it now; a real LLM adapter (LlmAdCopyGenerator) is a deferred swap behind the same
 * method (cf. LanguageDetector). The stage validates output regardless of source.
 */
interface AdCopyGenerator
{
    public function generate(AdCopyRequest $request): AdCopy;
}
