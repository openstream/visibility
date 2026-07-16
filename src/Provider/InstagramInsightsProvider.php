<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;
use Openstream\Visibility\OAuth\OAuthTokenStore;

/**
 * Echte Kennzahlen eines per OAuth verbundenen Instagram-Business/Creator-Kontos über
 * „Instagram API with Instagram Login" (graph.instagram.com, Scopes instagram_business_basic
 * + instagram_business_manage_insights). Der Kunde meldet sich direkt mit seinem Instagram-
 * Konto an — KEINE Facebook-Seite und kein /me/accounts-Umweg nötig.
 *
 * Liefert die ECHTEN Monats-Views des Berichtsmonats (Metrik „views", period=day,
 * metric_type=total_value über since/until aggregiert) plus Reach und Follower. Anders als
 * YouTube/TikTok braucht es kein Delta — Instagram liefert den Zeitraumswert direkt.
 */
final class InstagramInsightsProvider implements ConnectedSocialProvider
{
    private const GRAPH = 'https://graph.instagram.com/';

    private Client $http;

    public function __construct(private readonly OAuthTokenStore $store, ?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 30]);
    }

    public function name(): string
    {
        return 'instagram';
    }

    public function collectConnected(array $connection, string $measuredAt): array
    {
        $token = $this->store->accessTokenFor($connection);
        [$start, $end] = $this->monthUnixRange($measuredAt);

        $views = $this->insightTotal('views', $start, $end, $token);
        $reach = $this->insightTotal('reach', $start, $end, $token);
        $followers = $this->followerCount($token);

        return [new SocialMetric(
            platform:     'instagram',
            account:      (string) ($connection['account_label'] ?? $connection['account_ref'] ?? 'instagram'),
            followers:    $followers,
            viewsTotal:   null, // Instagram liefert Zeitraumswerte direkt, kein Lifetime-Delta nötig
            postsTotal:   null,
            source:       'instagram_graph',
            monthlyViews: $views,
        )];
    }

    /**
     * Summe einer Account-Insights-Metrik (reach/views) über den Zeitraum (total_value),
     * für das eigene verbundene Konto (/me). graph.instagram.com mit dem Instagram-User-Token.
     */
    private function insightTotal(string $metric, int $since, int $until, string $token): ?int
    {
        $res = $this->http->get(self::GRAPH . 'me/insights', [
            'query' => [
                'metric'      => $metric,
                'period'      => 'day',
                'metric_type' => 'total_value',
                'since'       => $since,
                'until'       => $until,
                'access_token' => $token,
            ],
        ]);
        $data = json_decode((string) $res->getBody(), true)['data'] ?? [];
        foreach ($data as $row) {
            if (($row['name'] ?? null) === $metric && isset($row['total_value']['value'])) {
                return (int) $row['total_value']['value'];
            }
        }
        return null;
    }

    private function followerCount(string $token): ?int
    {
        $res = $this->http->get(self::GRAPH . 'me', [
            'query' => ['fields' => 'followers_count', 'access_token' => $token],
        ]);
        $data = json_decode((string) $res->getBody(), true);
        return isset($data['followers_count']) ? (int) $data['followers_count'] : null;
    }

    /**
     * Unix-Zeitstempel für Monatsanfang und -ende (des Monats, in dem measuredAt liegt).
     * @return array{0:int,1:int}
     */
    public static function monthUnixRange(string $measuredAt): array
    {
        $start = (int) strtotime(date('Y-m-01 00:00:00', strtotime($measuredAt)));
        $end = (int) strtotime(date('Y-m-t 23:59:59', strtotime($measuredAt)));
        return [$start, $end];
    }
}
