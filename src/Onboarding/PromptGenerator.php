<?php

declare(strict_types=1);

namespace Openstream\Visibility\Onboarding;

use Openstream\Visibility\Provider\ClaudeClient;

/**
 * Generiert aus Website-Profil (Innensicht) + echten Signalen (Aussensicht:
 * GSC-Queries etc.) Vorschläge für Keywords und GEO-Prompts. Ergebnis geht in den
 * Onboarding-Report zur Kundenfreigabe.
 */
final class PromptGenerator
{
    private const SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'keywords' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string'],
                        'reason'  => ['type' => 'string', 'description' => 'kurze Begründung + Quell-Signal'],
                    ],
                    'required'             => ['keyword', 'reason'],
                    'additionalProperties' => false,
                ],
            ],
            'geo_prompts' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'type'   => ['type' => 'string', 'enum' => ['category', 'brand']],
                        'prompt' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required'             => ['type', 'prompt', 'reason'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required'             => ['keywords', 'geo_prompts'],
        'additionalProperties' => false,
    ];

    public function __construct(private readonly ClaudeClient $claude) {}

    /**
     * @param array<string,mixed>       $profile     website_profile (aus WebsiteAnalyzer)
     * @param array<int,string>         $gscQueries  echte Suchanfragen aus GSC
     * @param array<int,string>         $competitors bekannte Wettbewerber-Domains
     * @return array{keywords:array<int,array<string,string>>,geo_prompts:array<int,array<string,string>>}
     */
    public function generate(array $profile, array $gscQueries, array $competitors = []): array
    {
        $system = 'Du bist SEO-/GEO-Stratege für den Schweizer Markt. Erzeuge Keywords (für '
            . 'Google-Rankings) und GEO-Prompts (natürliche Fragen, mit denen wir prüfen, ob die '
            . 'Marke in KI-Antworten von ChatGPT/Perplexity/Gemini erscheint). '
            . 'Regeln: Deutsch, CH-lokalisiert (Region/Kanton wenn passend). GEO-Prompts kurz & '
            . 'nutzernah (keine Marketing-Templates). Buckets: category = Kategorie-/Kaufabsicht '
            . '("bester Anbieter für X in Region", "X für Zielgruppe", "Alternativen zu Wettbewerber"); '
            . 'brand = Marken-Wissen ("Was ist <Marke>?"). Liefere ca. 15-20 Keywords und genau '
            . '8 GEO-Prompts (5 category, 3 brand). Jede Zeile mit kurzer Begründung + Quell-Signal.';

        $prompt = "=== WEBSITE-PROFIL (Innensicht) ===\n"
            . json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n"
            . "=== ECHTE SUCHANFRAGEN aus Google Search Console (Aussensicht) ===\n"
            . ($gscQueries ? '- ' . implode("\n- ", array_slice($gscQueries, 0, 60)) : '(keine GSC-Daten verfügbar)') . "\n\n"
            . "=== WETTBEWERBER ===\n"
            . ($competitors ? '- ' . implode("\n- ", $competitors) : '(keine genannt — ggf. aus Kontext ableiten)') . "\n\n"
            . "Erzeuge daraus Keyword- und GEO-Prompt-Vorschläge.";

        return $this->claude->structuredJson($prompt, self::SCHEMA, $system, 6000);
    }
}
