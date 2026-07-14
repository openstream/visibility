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
}
