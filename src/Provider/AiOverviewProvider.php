<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * GEO-Sichtbarkeit in Google AI Overviews (die KI-Zusammenfassung oben in den
 * Google-Ergebnissen). Über DataForSEO SERP (load_async_ai_overview) — NICHT auf den
 * GSC-Report warten, der für CH-Domains noch nicht ausgerollt ist. AI Overviews
 * erscheinen für Keyword-Suchen, daher nutzen wir die getrackten KEYWORDS als Input
 * und prüfen, ob die Domain in den AI-Overview-Referenzen zitiert wird.
 *
 * engine=ai_overview, source=dataforseo_serp.
 */
final class AiOverviewProvider
{
    private const LOCATION_CH = 2756;

    public function __construct(
        private readonly DataForSeoClient $dfs,
        private readonly string $domain,
        private readonly string $languageCode = 'de',
    ) {}

    /**
     * @param array<int,string> $keywords getrackte Keywords (id => keyword)
     * @return array<int,GeoMention>  je Keyword ein Ergebnis (keine AIO → mentioned/cited false)
     */
    public function collect(array $keywords): array
    {
        $needle = $this->domainNeedle($this->domain);
        $out = [];

        foreach ($keywords as $keywordId => $keyword) {
            try {
                $res = $this->dfs->post('serp/google/organic/live/advanced', [[
                    'keyword'                => $keyword,
                    'language_code'          => $this->languageCode,
                    'location_code'          => self::LOCATION_CH,
                    'load_async_ai_overview' => true,
                ]]);
            } catch (\Throwable $e) {
                continue;
            }

            $items = $res['tasks'][0]['result'][0]['items'] ?? [];
            $aio = null;
            foreach ($items as $it) {
                if (($it['type'] ?? '') === 'ai_overview') {
                    $aio = $it;
                    break;
                }
            }

            // Keine AI Overview für dieses Keyword → nicht erfassen (kein Datenpunkt).
            if ($aio === null) {
                continue;
            }

            [$cited, $position, $citations] = self::findInReferences($aio, $needle);

            $out[] = new GeoMention(
                engine:      'ai_overview',
                promptId:    (int) $keywordId,   // hier: Keyword-ID
                mentioned:   $cited,             // in AIO = zitiert = sichtbar
                cited:       $cited,
                position:    $position,
                citations:   $citations,
                competitors: [],
                source:      'dataforseo_serp',
            );
        }
        return $out;
    }

    /**
     * Prüft, ob die Domain in den AI-Overview-Referenzen vorkommt.
     * public static = ohne API testbar.
     * @return array{0:bool,1:?int,2:array<int,string>} [gefunden, position, alle-referenz-urls]
     */
    public static function findInReferences(array $aio, string $needle): array
    {
        $refs = $aio['references'] ?? [];
        $allUrls = [];
        $position = null;
        $found = false;
        $rank = 0;
        foreach ($refs as $ref) {
            $rank++;
            $d = mb_strtolower((string) ($ref['domain'] ?? ''));
            $url = (string) ($ref['url'] ?? '');
            if ($url !== '') {
                $allUrls[] = $url;
            }
            if (!$found && $d !== '' && str_contains($d, $needle)) {
                $found = true;
                $position = $rank; // Rang unter den zitierten Quellen
            }
        }
        return [$found, $position, $allUrls];
    }

    private function domainNeedle(string $domain): string
    {
        $d = strtolower($domain);
        $d = preg_replace('#^https?://#', '', $d);
        $d = preg_replace('#^www\.#', '', (string) $d);
        return rtrim((string) $d, '/');
    }
}
