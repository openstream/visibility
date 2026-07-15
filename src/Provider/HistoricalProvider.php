<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Historische Sichtbarkeit rückwirkend via DataForSEO Labs historical_rank_overview.
 * Liefert pro Monat die Ranking-Verteilung (pos_1 … pos_91_100), Keyword-Anzahl und
 * den geschätzten Traffic-Wert (etv) — der Sichtbarkeits-Proxy für die Verlaufskurve.
 * Beim Onboarding einmalig geladen, damit der erste Report sofort einen Verlauf zeigt.
 */
final class HistoricalProvider
{
    private const LOCATION_CH = 2756;

    public function __construct(
        private readonly DataForSeoClient $dfs,
        private readonly string $domain,
        private readonly string $languageCode = 'de',
    ) {}

    /**
     * @return array<int,array<string,mixed>> je Monat ein normalisierter Datensatz
     */
    public function overview(): array
    {
        $res = $this->dfs->post('dataforseo_labs/google/historical_rank_overview/live', [[
            'target'        => $this->normalizeDomain($this->domain),
            'location_code' => self::LOCATION_CH,
            'language_code' => $this->languageCode,
        ]]);

        $items = $res['tasks'][0]['result'][0]['items'] ?? [];
        $out = [];
        foreach ($items as $it) {
            $o = $it['metrics']['organic'] ?? [];
            if (!$o) {
                continue;
            }
            $year = (int) ($it['year'] ?? 0);
            $month = (int) ($it['month'] ?? 0);
            if ($year === 0 || $month === 0) {
                continue;
            }
            $out[] = [
                'engine'         => 'google',
                'period'         => sprintf('%04d-%02d', $year, $month),
                'keywords_total' => (int) ($o['count'] ?? 0),
                'pos_1'          => (int) ($o['pos_1'] ?? 0),
                'pos_2_3'        => (int) ($o['pos_2_3'] ?? 0),
                'pos_4_10'       => (int) ($o['pos_4_10'] ?? 0),
                'pos_11_20'      => (int) ($o['pos_11_20'] ?? 0),
                'pos_21_50'      => (int) ($o['pos_21_30'] ?? 0) + (int) ($o['pos_31_40'] ?? 0) + (int) ($o['pos_41_50'] ?? 0),
                'pos_51_100'     => (int) ($o['pos_51_60'] ?? 0) + (int) ($o['pos_61_70'] ?? 0)
                                    + (int) ($o['pos_71_80'] ?? 0) + (int) ($o['pos_81_90'] ?? 0) + (int) ($o['pos_91_100'] ?? 0),
                'etv'            => round((float) ($o['etv'] ?? 0), 2),
                'is_new'         => (int) ($o['is_new'] ?? 0),
                'is_lost'        => (int) ($o['is_lost'] ?? 0),
            ];
        }

        // Chronologisch sortieren (aufsteigend nach Periode).
        usort($out, static fn($a, $b) => strcmp($a['period'], $b['period']));
        return $out;
    }

    private function normalizeDomain(string $domain): string
    {
        $d = strtolower($domain);
        $d = preg_replace('#^https?://#', '', $d);
        $d = preg_replace('#^www\.#', '', (string) $d);
        return rtrim((string) $d, '/');
    }
}
