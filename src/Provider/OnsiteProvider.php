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

    public function __construct(private readonly DataForSeoClient $dfs) {}

    /**
     * Prüft mehrere URLs. @param array<int,string> $urls
     * @return array<int,array{url:string,title:?string,title_len:int,desc_len:int,h1:int,h2:int,internal:int,external:int,problems:array<int,string>}>
     */
    public function audit(array $urls): array
    {
        $out = [];
        foreach ($urls as $url) {
            try {
                $res = $this->dfs->post('on_page/instant_pages', [[
                    'url' => $url, 'enable_javascript' => false,
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

            $problems = [];
            foreach (self::PROBLEM_CHECKS as $key => $label) {
                if (($checks[$key] ?? false) === true) {
                    $problems[] = $label;
                }
            }

            $out[] = [
                'url'       => $url,
                'title'     => $meta['title'] ?? null,
                'title_len' => mb_strlen((string) ($meta['title'] ?? '')),
                'desc_len'  => mb_strlen((string) ($meta['description'] ?? '')),
                'h1'        => count($htags['h1'] ?? []),
                'h2'        => count($htags['h2'] ?? []),
                'internal'  => (int) ($meta['internal_links_count'] ?? 0),
                'external'  => (int) ($meta['external_links_count'] ?? 0),
                'problems'  => $problems,
            ];
        }
        return $out;
    }
}
