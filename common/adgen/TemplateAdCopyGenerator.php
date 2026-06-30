<?php

declare(strict_types=1);

namespace common\adgen;

/**
 * Deterministic, language-aware template ad-copy generator (§2.9). Chosen for
 * predictability + testability (no live LLM); a real LlmAdCopyGenerator is the
 * deferred swap behind AdCopyGenerator. Output is valid by construction (texts
 * capped to RSA limits, counts within range); the stage still validates it.
 *
 * Headlines = pinned brand (if any) + Title-Cased keywords + language CTAs.
 * Descriptions = language-specific snippets. All in the requested language.
 */
final class TemplateAdCopyGenerator implements AdCopyGenerator
{
    private const FALLBACK_LANGUAGE = 'en';

    /** Short call-to-action headlines (<=30) per language. */
    private const CALLS_TO_ACTION = [
        'en' => ['Easy Website Builder', 'Create Your Site Now', 'Start Building For Free', 'Build A Website Fast', 'Try It Free Today'],
        'ru' => ['Создайте сайт онлайн', 'Конструктор сайтов', 'Начните бесплатно', 'Просто и быстро', 'Сайт за минуты'],
        'pt' => ['Crie seu site online', 'Criador de sites', 'Comece grátis agora', 'Fácil e rápido', 'Site em minutos'],
        'es' => ['Crea tu sitio online', 'Creador de webs', 'Empieza gratis ya', 'Fácil y rápido', 'Web en minutos'],
        'de' => ['Website online erstellen', 'Homepage Baukasten', 'Jetzt kostenlos starten', 'Einfach und schnell', 'Seite in Minuten'],
    ];

    /** Description snippets (<=90) per language. */
    private const DESCRIPTIONS = [
        'en' => [
            'Create a professional website in minutes with our easy drag-and-drop builder.',
            'Build your free website today. No coding or design skills required.',
            'Launch a modern, mobile-ready site fast and grow your business online.',
            'Start your online presence now with a powerful, free website builder.',
        ],
        'ru' => [
            'Создайте профессиональный сайт за минуты. Без навыков программирования.',
            'Бесплатный конструктор сайтов. Начните прямо сейчас и опубликуйте сайт.',
            'Современный сайт для мобильных устройств быстро и просто.',
            'Запустите свой сайт онлайн уже сегодня абсолютно бесплатно.',
        ],
        'pt' => [
            'Crie um site profissional em minutos com nosso editor fácil de usar.',
            'Faça seu site grátis hoje. Sem precisar de código ou design.',
            'Lance um site moderno e responsivo rapidamente para seu negócio.',
            'Comece sua presença online agora com um criador de sites grátis.',
        ],
        'es' => [
            'Crea un sitio web profesional en minutos con nuestro editor fácil.',
            'Haz tu sitio web gratis hoy. Sin necesidad de código ni diseño.',
            'Lanza un sitio moderno y adaptable rápidamente para tu negocio.',
            'Empieza tu presencia online ahora con un creador de webs gratis.',
        ],
        'de' => [
            'Erstellen Sie in Minuten eine professionelle Website mit unserem Baukasten.',
            'Erstellen Sie Ihre kostenlose Website heute. Ganz ohne Programmierung.',
            'Starten Sie schnell eine moderne, mobiloptimierte Seite für Ihr Geschäft.',
            'Beginnen Sie jetzt Ihren Online-Auftritt mit einem kostenlosen Baukasten.',
        ],
    ];

    public function generate(AdCopyRequest $request): AdCopy
    {
        $language = isset(self::CALLS_TO_ACTION[$request->language]) ? $request->language : self::FALLBACK_LANGUAGE;

        $headlineTexts = [];
        $pinnedHeadlineIndex = null;
        if ($request->brandHeadline !== null && trim($request->brandHeadline) !== '') {
            $headlineTexts[] = $this->cap($request->brandHeadline, RsaLengthValidator::MAX_HEADLINE_LENGTH);
            $pinnedHeadlineIndex = 0;
        }
        foreach ($request->keywords as $keyword) {
            $headlineTexts[] = $this->cap($this->titleCase($keyword), RsaLengthValidator::MAX_HEADLINE_LENGTH);
        }
        foreach (self::CALLS_TO_ACTION[$language] as $callToAction) {
            $headlineTexts[] = $this->cap($callToAction, RsaLengthValidator::MAX_HEADLINE_LENGTH);
        }
        $headlineTexts = array_slice($this->uniqueNonEmpty($headlineTexts), 0, RsaLengthValidator::MAX_HEADLINES);

        $descriptionTexts = array_slice(
            array_map(fn (string $d): string => $this->cap($d, RsaLengthValidator::MAX_DESCRIPTION_LENGTH), self::DESCRIPTIONS[$language]),
            0,
            RsaLengthValidator::MAX_DESCRIPTIONS
        );

        return AdCopy::of($headlineTexts, $descriptionTexts, $pinnedHeadlineIndex);
    }

    private function cap(string $text, int $maxLength): string
    {
        return mb_substr(trim($text), 0, $maxLength, 'UTF-8');
    }

    private function titleCase(string $text): string
    {
        return mb_convert_case($text, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @param string[] $texts
     * @return string[]
     */
    private function uniqueNonEmpty(array $texts): array
    {
        return array_values(array_unique(array_filter($texts, static fn (string $text): bool => $text !== '')));
    }
}
