<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Rankings aus Google Search Console (eigene, verifizierte Property).
 * Liefert für die getrackten Keywords echte Position, Klicks, Impressionen, CTR —
 * gemittelt über den Erhebungszeitraum. Nur Keywords, die als GSC-Query auftauchen.
 */
final class GscSerpProvider implements SerpProvider
{
    /**
     * @param GscClient $gsc
     * @param string $siteUrl  exakte Property-URL (z.B. https://www.openstream.ch/)
     * @param int $days        Zeitfenster für die Erhebung (Default 28)
     */
    public function __construct(
        private readonly GscClient $gsc,
        private readonly string $siteUrl,
        private readonly int $days = 28,
    ) {}

    public function name(): string
    {
        return 'gsc';
    }

    public function collect(array $keywords): array
    {
        // GSC liefert Query + page in einem Rutsch; wir aggregieren pro Query.
        $end = date('Y-m-d', strtotime('-3 days'));   // GSC-Daten ~2-3 Tage verzögert
        $start = date('Y-m-d', strtotime("-{$this->days} days"));
        $rows = $this->gsc->searchAnalytics($this->siteUrl, $start, $end, ['query', 'page'], 5000);

        // Query (lowercase) → beste Zeile (nach Impressionen) für URL-Zuordnung.
        $byQuery = [];
        foreach ($rows as $r) {
            $q = mb_strtolower((string) ($r['keys'][0] ?? ''));
            if ($q === '') {
                continue;
            }
            if (!isset($byQuery[$q]) || ($r['impressions'] ?? 0) > $byQuery[$q]['impressions']) {
                $byQuery[$q] = [
                    'position'    => $r['position'] ?? null,
                    'url'         => $r['keys'][1] ?? null,
                    'impressions' => (int) ($r['impressions'] ?? 0),
                    'clicks'      => (int) ($r['clicks'] ?? 0),
                    'ctr'         => $r['ctr'] ?? null,
                ];
            }
        }

        $out = [];
        foreach ($keywords as $keywordId => $keyword) {
            $q = mb_strtolower(trim($keyword));
            if (!isset($byQuery[$q])) {
                continue; // Keyword erzeugte im Zeitraum keine GSC-Impression
            }
            $d = $byQuery[$q];
            $out[] = new Measurement(
                engine:      'google',
                keywordId:   (int) $keywordId,
                position:    $d['position'] !== null ? round((float) $d['position'], 2) : null,
                url:         $d['url'],
                impressions: $d['impressions'],
                clicks:      $d['clicks'],
                ctr:         $d['ctr'] !== null ? round((float) $d['ctr'] * 100, 3) : null,
                source:      'gsc',
            );
        }
        return $out;
    }
}
