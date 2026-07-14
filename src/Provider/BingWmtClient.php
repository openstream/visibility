<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;
use Openstream\Visibility\App;

/**
 * Bing Webmaster Tools API (REST/JSON). Auth via ?apikey=. Kostenlos, für
 * verifizierte Properties. Base: https://ssl.bing.com/webmaster/api.svc/json
 *
 * Hinweis: Der Bing "AI Performance"-Report (Copilot/Bing-Summaries-Citations) hat
 * noch KEINE API — dieser Client deckt nur die klassischen WMT-Daten ab.
 */
class BingWmtClient
{
    private const BASE = 'https://ssl.bing.com/webmaster/api.svc/json/';

    private Client $http;

    public function __construct(private readonly string $apiKey, ?Client $http = null)
    {
        $this->http = $http ?? new Client(['base_uri' => self::BASE, 'timeout' => 60]);
    }

    public static function fromEnv(): self
    {
        $key = App::get()->env('BING_WEBMASTER_API_KEY');
        if (!$key) {
            throw new \RuntimeException('BING_WEBMASTER_API_KEY fehlt in .env');
        }
        return new self($key);
    }

    /** @return array<int,string> verifizierte Property-URLs */
    public function userSites(): array
    {
        $data = $this->get('GetUserSites');
        return array_map(static fn($s) => (string) ($s['Url'] ?? ''), $data['d'] ?? []);
    }

    /**
     * Query-Statistiken (Impressionen, Klicks, Position) pro Query/Datum.
     * @return array<int,array<string,mixed>>
     */
    public function queryStats(string $siteUrl): array
    {
        $data = $this->get('GetQueryStats', ['siteUrl' => $siteUrl]);
        return $data['d'] ?? [];
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    protected function get(string $method, array $params = []): array
    {
        $query = array_merge(['apikey' => $this->apiKey], $params);
        $res = $this->http->get($method, ['query' => $query]);
        $data = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException("Bing WMT {$method}: unerwartete Antwort.");
        }
        return $data;
    }
}
