<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;
use Openstream\Visibility\OAuth\OAuthTokenStore;

/**
 * Echte Kennzahlen eines per OAuth verbundenen TikTok-Kanals via TikTok Display API
 * (Scopes user.info.stats + video.list). Liefert Follower (user/info/) und die kumulierte
 * Sichtbarkeit als Summe der View-Counts der Videos (video/list/).
 *
 * TikTok liefert KEINE fertigen „Monats-Views". Wir speichern die Lifetime-Summe als
 * viewsTotal; die Monats-Views bildet socialMonthly() als Differenz zweier wöchentlicher
 * Stände (gleiches Delta-Prinzip wie bei der YouTube Data API).
 *
 * Die Video-Liste ist paginiert (max_count ≤ 20). Wir summieren über mehrere Seiten bis
 * MAX_PAGES, damit ein Lauf begrenzt bleibt (kein unbeschränktes Durchblättern grosser
 * Kanäle). Wird das Limit erreicht, ist viewsTotal eine Untergrenze — für das Monats-Delta
 * unkritisch, solange die Schwelle konsistent ist.
 */
final class TikTokProvider implements ConnectedSocialProvider
{
    private const USER_URL  = 'https://open.tiktokapis.com/v2/user/info/';
    private const VIDEO_URL = 'https://open.tiktokapis.com/v2/video/list/';
    private const MAX_PAGES = 20; // ≤ 20 Videos/Seite → bis zu 400 Videos aggregiert

    private Client $http;

    public function __construct(private readonly OAuthTokenStore $store, ?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 30]);
    }

    public function name(): string
    {
        return 'tiktok';
    }

    public function collectConnected(array $connection, string $measuredAt): array
    {
        $token = $this->store->accessTokenFor($connection);
        $auth = ['Authorization' => 'Bearer ' . $token];

        // 1) Profil-Stats (Follower, Gesamt-Likes, Video-Anzahl).
        $userRes = $this->http->get(self::USER_URL, [
            'headers' => $auth,
            'query'   => ['fields' => 'display_name,follower_count,likes_count,video_count'],
        ]);
        $user = (json_decode((string) $userRes->getBody(), true)['data']['user'] ?? []);

        // 2) View-Counts über alle Videos summieren (paginiert).
        [$viewsTotal, $countedVideos] = $this->sumVideoViews($auth);

        $account = (string) ($connection['account_ref'] ?? $connection['account_label']
            ?? ($user['display_name'] ?? 'tiktok'));

        return [new SocialMetric(
            platform:     'tiktok',
            account:      $account,
            followers:    isset($user['follower_count']) ? (int) $user['follower_count'] : null,
            viewsTotal:   $viewsTotal,
            postsTotal:   isset($user['video_count']) ? (int) $user['video_count'] : $countedVideos,
            source:       'tiktok_api',
            monthlyViews: null, // TikTok liefert keine echten Monats-Views → Delta via socialMonthly()
        )];
    }

    /**
     * Summiert die View-Counts aller Videos über die paginierte video/list/-API.
     * @param array<string,string> $auth
     * @return array{0:int,1:int} [Views gesamt, Anzahl gezählter Videos]
     */
    private function sumVideoViews(array $auth): array
    {
        $views = 0;
        $counted = 0;
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = ['max_count' => 20];
            if ($cursor !== null) {
                $body['cursor'] = $cursor;
            }
            $res = $this->http->post(self::VIDEO_URL, [
                'headers' => $auth,
                'query'   => ['fields' => 'id,view_count,like_count,comment_count,share_count,create_time'],
                'json'    => $body,
            ]);
            $data = json_decode((string) $res->getBody(), true)['data'] ?? [];
            $videos = $data['videos'] ?? [];
            foreach ($videos as $v) {
                $views += self::viewCount($v);
                $counted++;
            }
            if (empty($data['has_more']) || !isset($data['cursor'])) {
                break;
            }
            $cursor = $data['cursor'];
        }

        return [$views, $counted];
    }

    /**
     * View-Count eines Video-Objekts, tolerant gegenüber der Feldbenennung
     * (Display API: view_count; manche Antworten/Varianten: play_count).
     * @param array<string,mixed> $video
     */
    public static function viewCount(array $video): int
    {
        foreach (['view_count', 'play_count', 'video_views'] as $k) {
            if (isset($video[$k]) && is_numeric($video[$k])) {
                return (int) $video[$k];
            }
        }
        return 0;
    }
}
