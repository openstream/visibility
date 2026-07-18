<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * CH-Suchvolumen je Keyword via DataForSEO Keywords Data (Google Ads search_volume, live).
 * Ein Request deckt bis zu 1000 Keywords ab und kostet nur EINMAL (~$0.09), egal wie viele
 * Keywords — daher günstig für den ganzen Keyword-Satz. Suchvolumen ändert sich langsam →
 * quartalsweise/manuell aktualisieren, nicht monatlich.
 *
 * Nicht alle Keywords haben ein messbares Google-Ads-Volumen (Nischenbegriffe): dann fehlt
 * der Eintrag → wird als null gespeichert (ehrlich, kein 0 geraten).
 */
final class KeywordVolumeProvider
{
    private const LOCATION_SWITZERLAND = 2756;

    public function __construct(private readonly DataForSeoClient $dfs) {}

    /**
     * Holt Suchvolumen/Wettbewerb/CPC für die Keywords (CH, Deutsch).
     * @param array<int,string> $keywords
     * @return array<string,array{search_volume:?int,competition:?string,cpc:?float}> keyword(lower) => Werte
     */
    public function volumes(array $keywords): array
    {
        $keywords = array_values(array_filter(array_map('trim', $keywords)));
        if (!$keywords) {
            return [];
        }

        $res = $this->dfs->post('keywords_data/google_ads/search_volume/live', [[
            'location_code' => self::LOCATION_SWITZERLAND,
            'language_code' => 'de',
            'keywords'      => array_slice($keywords, 0, 1000),
        ]]);
        $items = $res['tasks'][0]['result'] ?? [];

        $out = [];
        foreach ($items as $it) {
            $kw = mb_strtolower(trim((string) ($it['keyword'] ?? '')));
            if ($kw === '') {
                continue;
            }
            $out[$kw] = [
                'search_volume' => isset($it['search_volume']) ? (int) $it['search_volume'] : null,
                'competition'   => isset($it['competition']) ? (string) $it['competition'] : null,
                'cpc'           => isset($it['cpc']) ? round((float) $it['cpc'], 2) : null,
            ];
        }
        return $out;
    }
}
