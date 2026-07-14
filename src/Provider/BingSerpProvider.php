<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Bing-Rankings aus den Bing Webmaster Tools (eigene, verifizierte Property).
 * Aggregiert die (query, datum)-Zeilen von GetQueryStats pro Query: Impressionen
 * und Klicks summiert, Position impressions-gewichtet gemittelt. Ordnet den
 * approved Keywords zu. engine=bing, source=bing_wmt.
 */
final class BingSerpProvider implements SerpProvider
{
    public function __construct(
        private readonly BingWmtClient $bing,
        private readonly string $siteUrl,
    ) {}

    public function name(): string
    {
        return 'bing_wmt';
    }

    public function collect(array $keywords): array
    {
        $rows = $this->bing->queryStats($this->siteUrl);

        // Pro Query (lowercase) aggregieren: Impr/Klicks summieren,
        // Position gewichtet nach Impressionen mitteln.
        $agg = [];
        foreach ($rows as $r) {
            $q = mb_strtolower(trim((string) ($r['Query'] ?? '')));
            if ($q === '') {
                continue;
            }
            $impr = (int) ($r['Impressions'] ?? 0);
            $pos = $r['AvgImpressionPosition'] ?? null;
            if (!isset($agg[$q])) {
                $agg[$q] = ['impr' => 0, 'clicks' => 0, 'posWeighted' => 0.0, 'posWeight' => 0];
            }
            $agg[$q]['impr'] += $impr;
            $agg[$q]['clicks'] += (int) ($r['Clicks'] ?? 0);
            if ($pos !== null && $impr > 0) {
                $agg[$q]['posWeighted'] += (float) $pos * $impr;
                $agg[$q]['posWeight'] += $impr;
            }
        }

        $out = [];
        foreach ($keywords as $keywordId => $keyword) {
            $q = mb_strtolower(trim($keyword));
            if (!isset($agg[$q])) {
                continue; // Keyword erzeugte auf Bing keine Impression
            }
            $a = $agg[$q];
            $position = $a['posWeight'] > 0 ? round($a['posWeighted'] / $a['posWeight'], 2) : null;
            $ctr = $a['impr'] > 0 ? round($a['clicks'] / $a['impr'] * 100, 3) : null;
            $out[] = new Measurement(
                engine:      'bing',
                keywordId:   (int) $keywordId,
                position:    $position,
                url:         null,           // GetQueryStats liefert keine URL
                impressions: $a['impr'],
                clicks:      $a['clicks'],
                ctr:         $ctr,
                source:      'bing_wmt',
            );
        }
        return $out;
    }
}
