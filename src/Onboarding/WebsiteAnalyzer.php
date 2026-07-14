<?php

declare(strict_types=1);

namespace Openstream\Visibility\Onboarding;

use Openstream\Visibility\Provider\ClaudeClient;
use Openstream\Visibility\Provider\ContentFetcher;

/**
 * Schritt 0 des Onboardings: die Website VERSTEHEN (Innensicht).
 * Holt relevante Seiten via API und leitet per LLM ein strukturiertes
 * website_profile ab (was die Seite ist, ihre Absicht, Angebot, Zielgruppe,
 * Region, Positionierung, Marke). Grundlage für die Keyword-/Prompt-Generierung.
 */
final class WebsiteAnalyzer
{
    /** JSON-Schema für das Website-Profil (erzwingt valides, vollständiges JSON). */
    private const SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'summary'     => ['type' => 'string', 'description' => 'Kurzbeschreibung: was die Seite ist und tut (2-3 Sätze).'],
            'intent'      => ['type' => 'string', 'description' => 'Hauptabsicht: verkaufen | leads | informieren | brand | mix.'],
            'offerings'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Konkrete Leistungen/Produkte.'],
            'audience'    => ['type' => 'string', 'description' => 'Zielgruppe (B2B/B2C, Branche).'],
            'region'      => ['type' => 'string', 'description' => 'Geografischer Fokus (CH/Kanton/Stadt), leer wenn unklar.'],
            'positioning' => ['type' => 'string', 'description' => 'USP / Positionierung / Tonalität.'],
            'brand_names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'NUR die EIGENEN Marken/Firmennamen DIESES Betreibers (der Domain). KEINE besprochenen Dritt-, Produkt- oder Wettbewerbermarken.'],
            'mentioned_third_parties' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Fremd-/Dritt-Marken, Plattformen oder Produkte, die im Inhalt nur BESPROCHEN/erwähnt werden (z.B. Tools, Anbieter, Wettbewerber) — gehören NICHT zum Betreiber.'],
            'topics'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Content-Themen / wichtige Bereiche.'],
        ],
        'required'             => ['summary', 'intent', 'offerings', 'audience', 'region', 'positioning', 'brand_names', 'mentioned_third_parties', 'topics'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private readonly ContentFetcher $fetcher,
        private readonly ClaudeClient $claude,
    ) {}

    /**
     * Analysiert eine Domain und gibt das website_profile zurück (+ analysierte URLs).
     *
     * @param array<int,string> $urls  zu analysierende Seiten (Startseite + Kernseiten)
     * @return array{profile:array<string,mixed>,source_urls:array<int,string>}
     */
    public function analyze(string $domain, array $urls): array
    {
        $corpus = $this->fetcher->corpus($urls);

        $system = 'Du bist ein SEO-/Marktanalyst. Analysiere den Inhalt einer Website und '
            . 'beschreibe präzise, WAS die Seite ist und WELCHE ABSICHT sie hat. Antworte auf Deutsch. '
            . 'Sei konkret und knapp; rate nicht, wenn etwas nicht im Inhalt steht (dann leer lassen). '
            . 'WICHTIG — Marken sauber trennen: `brand_names` enthält NUR die eigenen Marken des '
            . 'Betreibers dieser Domain. Marken, Tools, Produkte oder Anbieter, über die im Inhalt '
            . 'nur BERICHTET/geschrieben wird (typisch in Blog-/Vlog-Artikeln), sind DRITT-Marken und '
            . 'gehören ausschliesslich in `mentioned_third_parties` — niemals in `brand_names`. '
            . 'Im Zweifel: eine Marke, die nur Gegenstand eines Artikels ist, ist NICHT die eigene Marke.';

        $prompt = "Domain: {$domain}\n\n"
            . "Nachfolgend der Inhalt der wichtigsten Seiten dieser Website. "
            . "Leite daraus ein strukturiertes Profil ab.\n\n"
            . "=== WEBSITE-INHALT ===\n" . $corpus['corpus'];

        $profile = $this->claude->structuredJson($prompt, self::SCHEMA, $system);

        return [
            'profile'     => $profile,
            'source_urls' => array_map(static fn($p) => $p['url'], $corpus['pages']),
        ];
    }
}
