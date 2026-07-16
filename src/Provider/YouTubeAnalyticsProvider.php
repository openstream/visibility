<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;
use Openstream\Visibility\OAuth\OAuthTokenStore;

/**
 * Echte YouTube-Kennzahlen eines per OAuth verbundenen Kanals via YouTube Analytics API
 * (`youtubeAnalytics.reports.query`, Scope yt-analytics.readonly). Liefert die ECHTEN
 * Monats-Views (inkl. Shorts), nicht nur die Lifetime-Näherung der Data API.
 *
 * Erhebt month-to-date des Monats, in dem measuredAt liegt (vom Monatsersten bis measuredAt).
 * Da collect wöchentlich läuft, ist der jüngste Wochenwert im Monat der vollständigste
 * Monatswert — genau das, was socialMonthly() als „jüngsten Stand im Monat" nutzt.
 */
final class YouTubeAnalyticsProvider implements ConnectedSocialProvider
{
    private Client $http;

    public function __construct(private readonly OAuthTokenStore $store)
    {
        $this->http = new Client(['base_uri' => 'https://youtubeanalytics.googleapis.com/v2/', 'timeout' => 30]);
    }

    public function name(): string
    {
        return 'youtube';
    }

    public function collectConnected(array $connection, string $measuredAt): array
    {
        $token = $this->store->accessTokenFor($connection);
        $start = date('Y-m-01', strtotime($measuredAt));

        $res = $this->http->get('reports', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'query'   => [
                'ids'        => 'channel==MINE',
                'startDate'  => $start,
                'endDate'    => $measuredAt,
                'metrics'    => 'views,subscribersGained,subscribersLost',
            ],
        ]);
        $data = json_decode((string) $res->getBody(), true);
        $row = $data['rows'][0] ?? null;
        if ($row === null) {
            return [];
        }
        // Reihenfolge gemäss metrics-Parameter.
        $views = (int) ($row[0] ?? 0);
        $gained = (int) ($row[1] ?? 0);
        $lost = (int) ($row[2] ?? 0);

        return [new SocialMetric(
            platform:     'youtube',
            account:      (string) ($connection['account_label'] ?? $connection['account_ref'] ?? 'youtube'),
            followers:    null, // Netto-Sub-Änderung ist kein Gesamt-Follower-Wert; separat via Data API
            viewsTotal:   null,
            postsTotal:   null,
            source:       'youtube_analytics',
            monthlyViews: $views,
        )];
    }
}
