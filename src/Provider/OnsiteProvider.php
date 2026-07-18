<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Onsite/technisches SEO via DataForSEO OnPage (instant_pages) für die wichtigsten
 * Seiten. Prüft je URL Meta/Title/Description, Heading-Struktur, interne/externe
 * Links und die technischen Checks (Title zu lang, Duplicate Meta, fehlende
 * Alt-Texte, dünner Inhalt …). Rein API-basiert, kein eigener Crawler.
 */
final class OnsiteProvider
{
    /**
     * DataForSEO-Checks, die ein ECHTES Problem sind (viele „true"-Checks sind positiv,
     * z. B. is_https). Nur diese als Fehler zählen — mit kundenverständlichem Label.
     */
    private const PROBLEM_CHECKS = [
        'title_too_long'         => 'Seitentitel zu lang',
        'title_too_short'        => 'Seitentitel zu kurz',
        'no_title'               => 'Kein Seitentitel',
        'no_description'         => 'Keine Meta-Beschreibung',
        'duplicate_meta_tags'    => 'Doppelte Meta-Tags',
        'no_h1_tag'              => 'Keine H1-Überschrift',
        'low_content_rate'       => 'Wenig Textinhalt',
        'low_readability_rate'   => 'Geringe Lesbarkeit',
        'no_image_alt'           => 'Bilder ohne Alt-Text',
        'is_broken'              => 'Seite nicht erreichbar',
        'is_4xx_code'            => 'Fehlerseite (4xx)',
        'is_5xx_code'            => 'Serverfehler (5xx)',
        'no_favicon'             => 'Kein Favicon',
        'no_doctype'             => 'Kein HTML-Doctype',
        'canonical_to_broken'    => 'Canonical zeigt auf Fehlerseite',
        'has_render_blocking_resources' => 'Render-blockierende Ressourcen',
        'redirect_loop'          => 'Weiterleitungsschleife',
    ];

    /**
     * Positive Checks, die wir als «das ist gut» im Report zeigen (ausgewogenes Bild,
     * nicht nur Mängelliste). Nur solche, die für den Kunden aussagekräftig sind.
     */
    private const GOOD_CHECKS = [
        'is_https'         => 'HTTPS aktiv',
        'canonical'        => 'Canonical gesetzt',
        'seo_friendly_url' => 'SEO-freundliche URLs',
        'has_html_doctype' => 'Gültiger HTML-Doctype',
    ];

    public function __construct(private readonly DataForSeoClient $dfs) {}

    /**
     * Prüft mehrere URLs. @param array<int,string> $urls
     * @return array<int,array<string,mixed>>
     */
    public function audit(array $urls): array
    {
        $out = [];
        foreach ($urls as $url) {
            try {
                // enable_javascript für realistischere Ladezeiten (page_timing).
                $res = $this->dfs->post('on_page/instant_pages', [[
                    'url' => $url, 'enable_javascript' => true,
                ]]);
            } catch (\Throwable $e) {
                continue;
            }
            $item = $res['tasks'][0]['result'][0]['items'][0] ?? null;
            if ($item === null) {
                continue;
            }
            $meta = $item['meta'] ?? [];
            $checks = $item['checks'] ?? [];
            $htags = $meta['htags'] ?? [];
            $timing = $item['page_timing'] ?? [];

            $problems = [];
            foreach (self::PROBLEM_CHECKS as $key => $label) {
                if (($checks[$key] ?? false) === true) {
                    $problems[] = $label;
                }
            }
            $good = [];
            foreach (self::GOOD_CHECKS as $key => $label) {
                if (($checks[$key] ?? false) === true) {
                    $good[] = $label;
                }
            }

            // Social-Sharing: Open Graph / Twitter Card vorhanden?
            $social = $meta['social_media_tags'] ?? [];
            $hasOg = false;
            $hasTwitter = false;
            foreach (array_keys((array) $social) as $tag) {
                if (str_starts_with((string) $tag, 'og:')) {
                    $hasOg = true;
                }
                if (str_starts_with((string) $tag, 'twitter:')) {
                    $hasTwitter = true;
                }
            }

            $out[] = [
                'url'          => $url,
                'title'        => $meta['title'] ?? null,
                'title_len'    => mb_strlen((string) ($meta['title'] ?? '')),
                'desc_len'     => mb_strlen((string) ($meta['description'] ?? '')),
                'h1'           => count($htags['h1'] ?? []),
                'h2'           => count($htags['h2'] ?? []),
                'internal'     => (int) ($meta['internal_links_count'] ?? 0),
                'external'     => (int) ($meta['external_links_count'] ?? 0),
                'problems'     => $problems,
                'good'         => $good,
                'onpage_score' => isset($item['onpage_score']) ? round((float) $item['onpage_score'], 1) : null,
                // Ladezeiten in ms (die aussagekräftigsten: Interaktivität, DOM fertig, Serverantwort).
                'tti_ms'       => isset($timing['time_to_interactive']) ? (int) $timing['time_to_interactive'] : null,
                'dom_ms'       => isset($timing['dom_complete']) ? (int) $timing['dom_complete'] : null,
                'ttfb_ms'      => isset($timing['waiting_time']) ? (int) $timing['waiting_time'] : null,
                'has_og'       => $hasOg,
                'has_twitter'  => $hasTwitter,
            ];
        }
        return $out;
    }
}
