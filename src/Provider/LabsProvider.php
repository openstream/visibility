<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * DataForSEO Labs (Google, CH/Deutsch): die ECHTE Sichtbarkeitsbreite der eigenen Domain,
 * über die manuell getrackten Keywords hinaus.
 *  - rankedKeywords(): alle Keywords, für die die Domain rankt (Position + Volumen + ETV)
 *  - relevantPages():  welche eigenen Seiten die meisten Rankings/Traffic bringen
 *  - difficulty():     Ranking-Schwierigkeit 0-100 je Keyword (ergänzt das Suchvolumen)
 *
 * Kein Wettbewerber-Tracking (Leitplanke) — nur die eigene Domain.
 */
final class LabsProvider
{
    private const LOCATION_SWITZERLAND = 2756;

    public function __construct(
        private readonly DataForSeoClient $dfs,
        private readonly string $domain,
    ) {}

    /**
     * Alle Keywords, für die die Domain in CH rankt, Top nach geschätztem Traffic (ETV).
     * @return array{total:int,items:array<int,array{keyword:string,position:?int,volume:?int,etv:?float}>}
     */
    public function rankedKeywords(int $limit = 25): array
    {
        $res = $this->dfs->post('dataforseo_labs/google/ranked_keywords/live', [[
            'target'        => $this->normalize($this->domain),
            'location_code' => self::LOCATION_SWITZERLAND,
            'language_code' => 'de',
            'limit'         => $limit,
            'order_by'      => ['ranked_serp_element.serp_item.etv,desc'],
        ]]);
        $result = $res['tasks'][0]['result'][0] ?? [];
        $out = [];
        foreach ($result['items'] ?? [] as $it) {
            $serp = $it['ranked_serp_element']['serp_item'] ?? [];
            $out[] = [
                'keyword'  => (string) ($it['keyword_data']['keyword'] ?? ''),
                'position' => isset($serp['rank_absolute']) ? (int) $serp['rank_absolute'] : null,
                'volume'   => $it['keyword_data']['keyword_info']['search_volume'] ?? null,
                'etv'      => isset($serp['etv']) ? round((float) $serp['etv'], 1) : null,
            ];
        }
        return ['total' => (int) ($result['total_count'] ?? count($out)), 'items' => $out];
    }

    /**
     * Eigene Seiten mit den meisten organischen Rankings/Traffic.
     * @return array<int,array{page:string,keywords:int,etv:?float}>
     */
    public function relevantPages(int $limit = 10): array
    {
        $res = $this->dfs->post('dataforseo_labs/google/relevant_pages/live', [[
            'target'        => $this->normalize($this->domain),
            'location_code' => self::LOCATION_SWITZERLAND,
            'language_code' => 'de',
            'limit'         => $limit,
        ]]);
        $items = $res['tasks'][0]['result'][0]['items'] ?? [];
        $out = [];
        foreach ($items as $p) {
            $org = $p['metrics']['organic'] ?? [];
            $out[] = [
                'page'     => (string) ($p['page_address'] ?? ''),
                'keywords' => (int) ($org['count'] ?? 0),
                'etv'      => isset($org['etv']) ? round((float) $org['etv'], 1) : null,
            ];
        }
        return $out;
    }

    /**
     * Ranking-Schwierigkeit (0-100) je Keyword. @param array<int,string> $keywords
     * @return array<string,?int> keyword(lower) => difficulty
     */
    public function difficulty(array $keywords): array
    {
        $keywords = array_values(array_filter(array_map('trim', $keywords)));
        if (!$keywords) {
            return [];
        }
        $res = $this->dfs->post('dataforseo_labs/google/bulk_keyword_difficulty/live', [[
            'location_code' => self::LOCATION_SWITZERLAND,
            'language_code' => 'de',
            'keywords'      => array_slice($keywords, 0, 1000),
        ]]);
        $items = $res['tasks'][0]['result'][0]['items'] ?? [];
        $out = [];
        foreach ($items as $it) {
            $kw = mb_strtolower(trim((string) ($it['keyword'] ?? '')));
            if ($kw !== '') {
                $out[$kw] = isset($it['keyword_difficulty']) ? (int) $it['keyword_difficulty'] : null;
            }
        }
        return $out;
    }

    /**
     * Verwandte Keyword-Ideen zu Seed-Begriffen (CH/Deutsch), nach Volumen. Für die
     * Keyword-Findung im Onboarding — als SIGNAL (roh, streut breit, braucht Kuratierung
     * durch LLM + Mensch), nicht als fertige Keyword-Liste. Seeds sollten spezifisch sein
     * (z.B. «woocommerce agentur»), nicht generisch («shop»), sonst dominieren fremde Marken.
     *
     * @param array<int,string> $seeds
     * @return array<int,array{keyword:string,volume:?int}>
     */
    public function keywordIdeas(array $seeds, int $limit = 50): array
    {
        $seeds = array_values(array_filter(array_map('trim', $seeds)));
        if (!$seeds) {
            return [];
        }
        $res = $this->dfs->post('dataforseo_labs/google/keyword_ideas/live', [[
            'keywords'      => array_slice($seeds, 0, 200),
            'location_code' => self::LOCATION_SWITZERLAND,
            'language_code' => 'de',
            'limit'         => $limit,
            'order_by'      => ['keyword_info.search_volume,desc'],
        ]]);
        $items = $res['tasks'][0]['result'][0]['items'] ?? [];
        $out = [];
        foreach ($items as $it) {
            $kw = trim((string) ($it['keyword'] ?? ''));
            if ($kw !== '') {
                $out[] = [
                    'keyword' => $kw,
                    'volume'  => $it['keyword_info']['search_volume'] ?? null,
                ];
            }
        }
        return $out;
    }

    private function normalize(string $domain): string
    {
        $d = strtolower(preg_replace('#^https?://#', '', $domain));
        return rtrim(preg_replace('#^www\.#', '', (string) $d), '/');
    }
}
