<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;
use Openstream\Visibility\OAuth\OAuthTokenStore;

/**
 * Echte Kennzahlen eines per OAuth verbundenen Instagram-Business/Creator-Kontos via
 * Instagram Graph API (v21.0, Scopes instagram_basic + instagram_manage_insights).
 *
 * Liefert die ECHTEN Monats-Views des Berichtsmonats (Metrik „views", period=day,
 * metric_type=total_value über since/until aggregiert) plus Reach und Follower. Anders als
 * YouTube/TikTok braucht es kein Delta — Instagram liefert den Zeitraumswert direkt.
 *
 * Voraussetzung: Business- oder Creator-Konto, mit einer Facebook-Seite verknüpft; die
 * Instagram-Business-Account-ID wird beim ersten Lauf über /me/accounts ermittelt und als
 * account_ref gecacht (spart je Lauf zwei Calls).
 */
final class InstagramInsightsProvider implements ConnectedSocialProvider
{
    private const GRAPH = 'https://graph.facebook.com/v21.0/';

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

        $igId = (string) ($connection['account_ref'] ?? '');
        if ($igId === '') {
            $igId = $this->resolveIgUserId($token);
        }
        if ($igId === '') {
            return []; // kein verknüpftes IG-Business-Konto gefunden
        }

        [$start, $end] = $this->monthUnixRange($measuredAt);

        $views = $this->insightTotal($igId, 'views', $start, $end, $token);
        $reach = $this->insightTotal($igId, 'reach', $start, $end, $token);
        $followers = $this->followerCount($igId, $token);

        return [new SocialMetric(
            platform:     'instagram',
            account:      (string) ($connection['account_label'] ?? $igId),
            followers:    $followers,
            viewsTotal:   null, // Instagram liefert Zeitraumswerte direkt, kein Lifetime-Delta nötig
            postsTotal:   null,
            source:       'instagram_graph',
            monthlyViews: $views,
        )];
    }

    /**
     * Ermittelt die Instagram-Business-Account-ID über die verknüpfte Facebook-Seite.
     * /me/accounts → Page → instagram_business_account.id.
     */
    private function resolveIgUserId(string $token): string
    {
        $res = $this->http->get(self::GRAPH . 'me/accounts', [
            'query' => ['fields' => 'instagram_business_account', 'access_token' => $token],
        ]);
        $data = json_decode((string) $res->getBody(), true)['data'] ?? [];
        foreach ($data as $page) {
            $id = $page['instagram_business_account']['id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }
        return '';
    }

    /**
     * Summe einer Insights-Metrik (reach/views) über den Zeitraum (total_value).
     */
    private function insightTotal(string $igId, string $metric, int $since, int $until, string $token): ?int
    {
        $res = $this->http->get(self::GRAPH . $igId . '/insights', [
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

    private function followerCount(string $igId, string $token): ?int
    {
        $res = $this->http->get(self::GRAPH . $igId, [
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
