<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Offsite/Backlink-Profil via DataForSEO Backlinks Summary. Liefert die
 * Autoritäts-Kennzahl (DataForSEO Domain Rank, 0–1000 — KEIN offizieller Google-Wert,
 * es gibt keinen; Moz-DA/Ahrefs-DR sind ebenfalls Dritt-Schätzungen), referring
 * domains, Backlink-Anzahl, Broken Links, neue/verlorene Links.
 */
final class OffsiteProvider
{
    public function __construct(
        private readonly DataForSeoClient $dfs,
        private readonly string $domain,
    ) {}

    /**
     * @return array{domain_rank:?int,backlinks:?int,referring_domains:?int,broken:?int,new:?int,lost:?int}
     */
    public function summary(): array
    {
        $res = $this->dfs->post('backlinks/summary/live', [[
            'target'              => $this->normalizeDomain($this->domain),
            'internal_list_limit' => 1,
            'include_subdomains'  => true,
        ]]);
        $r = $res['tasks'][0]['result'][0] ?? [];

        return [
            'domain_rank'       => isset($r['rank']) ? (int) $r['rank'] : null,
            'backlinks'         => isset($r['backlinks']) ? (int) $r['backlinks'] : null,
            'referring_domains' => isset($r['referring_domains']) ? (int) $r['referring_domains'] : null,
            'broken'            => isset($r['broken_backlinks']) ? (int) $r['broken_backlinks'] : null,
            'new'               => isset($r['new_backlinks']) ? (int) $r['new_backlinks'] : null,
            'lost'              => isset($r['lost_backlinks']) ? (int) $r['lost_backlinks'] : null,
        ];
    }

    /**
     * Stärkste verweisende Domains (für konkrete Beispiel-Backlinks im Report).
     * Sortiert nach Domain-Rank absteigend. Quelle: DataForSEO backlinks/referring_domains.
     *
     * @return array<int,array{domain:string,rank:?int,backlinks:?int,dofollow:?int}>
     */
    public function topReferringDomains(int $limit = 10): array
    {
        $res = $this->dfs->post('backlinks/referring_domains/live', [[
            'target'             => $this->normalizeDomain($this->domain),
            'limit'              => $limit,
            'include_subdomains' => true,
            'order_by'           => ['rank,desc'],
        ]]);
        $items = $res['tasks'][0]['result'][0]['items'] ?? [];

        $out = [];
        foreach ($items as $it) {
            $domain = (string) ($it['domain'] ?? '');
            if ($domain === '') {
                continue;
            }
            $out[] = [
                'domain'    => $domain,
                'rank'      => isset($it['rank']) ? (int) $it['rank'] : null,
                'backlinks' => isset($it['backlinks']) ? (int) $it['backlinks'] : null,
                'dofollow'  => isset($it['dofollow']) ? (int) $it['dofollow'] : null,
            ];
        }
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
