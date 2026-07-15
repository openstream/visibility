<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;
use Openstream\Visibility\App;

/**
 * YouTube-Sichtbarkeit der EIGENEN Kunden-Kanäle via offizielle Data API v3.
 * Nur API-Key, KEIN OAuth. `channels.list?part=statistics` liefert:
 *   - viewCount (kumulierte Lifetime-Views, inkl. Shorts seit 31.3.2025)
 *   - subscriberCount (gerundet)
 *   - videoCount
 * Monats-Views leitet der Report aus der Differenz zweier viewCount-Stände ab.
 *
 * Akzeptiert je Account: rohe Kanal-ID (UC...), Kanal-URL (/channel/UC...),
 * oder Handle (@name bzw. /@name / handle-URL). Handles werden per
 * `channels.list?forHandle=` aufgelöst (spart einen extra search-Call).
 */
final class YouTubeProvider implements SocialProvider
{
    private Client $http;
    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $apiKey ??= App::get()->env('YOUTUBE_API_KEY');
        if (!$apiKey) {
            throw new \RuntimeException('YOUTUBE_API_KEY fehlt in .env');
        }
        $this->apiKey = $apiKey;
        $this->http = new Client(['base_uri' => 'https://www.googleapis.com/youtube/v3/', 'timeout' => 30]);
    }

    public static function fromEnv(): self
    {
        return new self();
    }

    public function name(): string
    {
        return 'youtube';
    }

    public function collect(array $accounts): array
    {
        $out = [];
        foreach ($accounts as $account) {
            $account = trim((string) $account);
            if ($account === '') {
                continue;
            }
            try {
                $stats = $this->channelStats($account);
            } catch (\Throwable) {
                continue; // einzelnen Kanal überspringen, Lauf nicht abbrechen
            }
            if ($stats === null) {
                continue;
            }
            $out[] = new SocialMetric(
                platform:   'youtube',
                account:    $account,
                followers:  isset($stats['subscriberCount']) ? (int) $stats['subscriberCount'] : null,
                viewsTotal: isset($stats['viewCount']) ? (int) $stats['viewCount'] : null,
                postsTotal: isset($stats['videoCount']) ? (int) $stats['videoCount'] : null,
                source:     'youtube_data_api',
            );
        }
        return $out;
    }

    /**
     * Holt das statistics-Objekt eines Kanals. Wählt den passenden Lookup-Parameter
     * (id | forHandle) je nach Account-Format.
     * @return array<string,mixed>|null
     */
    private function channelStats(string $account): ?array
    {
        [$param, $value] = self::resolve($account);
        $res = $this->http->get('channels', ['query' => [
            'part'    => 'statistics',
            $param    => $value,
            'key'     => $this->apiKey,
        ]]);
        $data = json_decode((string) $res->getBody(), true);
        return $data['items'][0]['statistics'] ?? null;
    }

    /**
     * Bestimmt aus einem Account-String den Lookup-Parameter und -Wert.
     * Public static für Testbarkeit (zustandslos).
     * @return array{0:string,1:string} [param, value]
     */
    public static function resolve(string $account): array
    {
        // Kanal-ID direkt (UC + 22 Zeichen) oder /channel/UC... URL.
        if (preg_match('#(UC[\w-]{22})#', $account, $m)) {
            return ['id', $m[1]];
        }
        // Handle: @name, /@name, oder youtube.com/@name.
        if (preg_match('#@([\w.-]+)#', $account, $m)) {
            return ['forHandle', '@' . $m[1]];
        }
        // Fallback: als Handle behandeln (roher Name ohne @).
        return ['forHandle', '@' . ltrim($account, '@')];
    }
}
