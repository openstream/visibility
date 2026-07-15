<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;
use Openstream\Visibility\App;

/**
 * Generischer Apify-Client (analog DataForSeoClient): führt einen Actor synchron aus und
 * gibt die Dataset-Items direkt als Array zurück. Ein Endpoint reicht:
 *   POST /v2/acts/{actorId}/run-sync-get-dataset-items?token=...
 *
 * Nur für die EIGENEN öffentlichen Kunden-Accounts (TikTok/Instagram-Gesamt-Views).
 * Kein OAuth. actorId + Input werden als Parameter übergeben, damit neue Actors ohne
 * Umbau andockbar sind.
 */
final class ApifyClient
{
    private Client $http;
    private string $token;

    public function __construct(?string $token = null)
    {
        $token ??= App::get()->env('APIFY_API_TOKEN');
        if (!$token) {
            throw new \RuntimeException('APIFY_API_TOKEN fehlt in .env');
        }
        $this->token = $token;
        $this->http = new Client(['base_uri' => 'https://api.apify.com/v2/', 'timeout' => 300]);
    }

    /**
     * Führt einen Actor synchron aus und gibt die Dataset-Items zurück.
     * @param array<string,mixed> $input Actor-Input (JSON-Body)
     * @return array<int,array<string,mixed>>
     */
    public function runActor(string $actorId, array $input): array
    {
        // actorId in der URL: der "/" im Namen (user/name) muss als "~" kodiert werden.
        $path = 'acts/' . str_replace('/', '~', $actorId) . '/run-sync-get-dataset-items';
        $res = $this->http->post($path, [
            'query' => ['token' => $this->token],
            'json'  => $input,
        ]);
        $data = json_decode((string) $res->getBody(), true);
        return is_array($data) ? $data : [];
    }
}
