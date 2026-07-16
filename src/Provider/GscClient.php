<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;
use Openstream\Visibility\App;

/**
 * Google Search Console (read-only). Nutzt den dependency-freien Token-Helper
 * (~/.config/gcloud-keys/gsc_token.sh), der per openssl einen ~1h-Token mit
 * webmasters.readonly-Scope mintet. KEIN google-auth-SDK nötig.
 *
 * .env: GSC_KEYFILE (Service-Account-JSON, ausserhalb Repo), GSC_TOKEN_HELPER.
 */
class GscClient
{
    private Client $http;
    private ?string $token = null;

    public function __construct(
        private readonly string $tokenHelper,
        private readonly string $keyFile,
    ) {
        $this->http = new Client(['base_uri' => 'https://www.googleapis.com/', 'timeout' => 60]);
    }

    public static function fromEnv(): self
    {
        $app = App::get();
        $helper = $app->env('GSC_TOKEN_HELPER');
        $key = $app->env('GSC_KEYFILE');
        if (!$helper || !$key) {
            throw new \RuntimeException('GSC_TOKEN_HELPER / GSC_KEYFILE fehlen in .env');
        }
        return new self($helper, $key);
    }

    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        // Token-Helper mit dem konfigurierten Key aufrufen.
        $cmd = 'GSC_KEYFILE=' . escapeshellarg($this->keyFile) . ' ' . escapeshellarg($this->tokenHelper) . ' 2>&1';
        $out = trim((string) shell_exec($cmd));
        if (!preg_match('/^(ya29|ey)/', $out)) {
            throw new \RuntimeException("GSC-Token konnte nicht gemintet werden: {$out}");
        }
        return $this->token = $out;
    }

    /** Properties, die der Service-Account sehen darf. @return array<int,array<string,mixed>> */
    public function sites(): array
    {
        $res = $this->http->get('webmasters/v3/sites', [
            'headers' => ['Authorization' => 'Bearer ' . $this->token()],
        ]);
        $data = json_decode((string) $res->getBody(), true);
        return $data['siteEntry'] ?? [];
    }

    /**
     * Search-Analytics-Query. $siteUrl exakt wie in sites() (z.B. https://www.openstream.ch/).
     *
     * @param array<int,string> $dimensions z.B. ['query'], ['page','query'], ['date']
     * @return array<int,array<string,mixed>> rows
     */
    public function searchAnalytics(
        string $siteUrl,
        string $startDate,
        string $endDate,
        array $dimensions = ['query'],
        int $rowLimit = 100,
    ): array {
        $encoded = rawurlencode($siteUrl);
        $res = $this->http->post("webmasters/v3/sites/{$encoded}/searchAnalytics/query", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token(),
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'startDate'  => $startDate,
                'endDate'    => $endDate,
                'dimensions' => $dimensions,
                'rowLimit'   => $rowLimit,
            ],
        ]);
        $data = json_decode((string) $res->getBody(), true);
        return $data['rows'] ?? [];
    }

    /**
     * Gesamt-Kennzahlen der Property (ohne Dimension) — der echte Website-Traffic,
     * NICHT die Summe der einzelnen Query-Zeilen (die untererfasst wegen anonymisierter
     * seltener Anfragen). Das ist die Zahl, die in der GSC-Übersicht steht.
     *
     * @return array{clicks:int,impressions:int,ctr:float,position:float}
     */
    public function totals(string $siteUrl, string $startDate, string $endDate): array
    {
        $rows = $this->searchAnalytics($siteUrl, $startDate, $endDate, [], 1);
        $r = $rows[0] ?? [];
        return [
            'clicks'      => (int) ($r['clicks'] ?? 0),
            'impressions' => (int) ($r['impressions'] ?? 0),
            'ctr'         => round((float) ($r['ctr'] ?? 0) * 100, 2),
            'position'    => round((float) ($r['position'] ?? 0), 1),
        ];
    }

    /**
     * ECHTE Positions-Verteilung aus GSC (alle Queries des Zeitraums nach Position gebucketet).
     * Zeigt, für wie viele Suchanfragen die Website tatsächlich auf #1 / Top-3 / … rankt —
     * anders als DataForSEO, das nur die getrackten Keywords im Gesamtmarkt misst.
     *
     * $minImpressions filtert Long-Tail-Rauschen (viele #1-Treffer haben nur 1 Impression);
     * die relevante Verteilung nutzt eine sinnvolle Schwelle, die Gesamtzahl bleibt daneben.
     *
     * @return array{total:int,relevant:int,pos_1:int,pos_2_3:int,pos_4_10:int,pos_11_20:int,pos_21_50:int,pos_51_100:int}
     */
    public function positionDistribution(string $siteUrl, string $startDate, string $endDate, int $minImpressions = 0): array
    {
        $rows = $this->searchAnalytics($siteUrl, $startDate, $endDate, ['query'], 25000);
        return self::bucketByPosition($rows, $minImpressions);
    }

    /**
     * Bucketet GSC-Query-Zeilen nach Position. Rein (testbar).
     * @param array<int,array<string,mixed>> $rows
     * @return array{total:int,relevant:int,pos_1:int,pos_2_3:int,pos_4_10:int,pos_11_20:int,pos_21_50:int,pos_51_100:int}
     */
    public static function bucketByPosition(array $rows, int $minImpressions = 0): array
    {
        $b = ['total' => 0, 'relevant' => 0, 'pos_1' => 0, 'pos_2_3' => 0, 'pos_4_10' => 0,
            'pos_11_20' => 0, 'pos_21_50' => 0, 'pos_51_100' => 0];
        foreach ($rows as $r) {
            $b['total']++;
            if ((int) ($r['impressions'] ?? 0) < $minImpressions) {
                continue;
            }
            $p = (float) ($r['position'] ?? 999);
            $b['relevant']++;
            if ($p < 1.5) {
                $b['pos_1']++;
            } elseif ($p < 3.5) {
                $b['pos_2_3']++;
            } elseif ($p <= 10.5) {
                $b['pos_4_10']++;
            } elseif ($p <= 20.5) {
                $b['pos_11_20']++;
            } elseif ($p <= 50.5) {
                $b['pos_21_50']++;
            } elseif ($p <= 100.5) {
                $b['pos_51_100']++;
            }
        }
        return $b;
    }
}
