<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * TikTok-Sichtbarkeit der EIGENEN Kunden-Accounts via Apify (Profil-Scraper).
 * Nur eigene, öffentliche Accounts (Gesamt-Views/Follower) — kein Wettbewerber-Tracking.
 *
 * Actor + genaue Feldnamen sind beim ersten echten Lauf am Gratis-Kontingent zu
 * verifizieren; wir lesen defensiv mehrere gängige Feldnamen. Default-Actor:
 * clockworks/tiktok-profile-scraper (etabliert). Über Config/Konstruktor überschreibbar.
 */
final class TikTokProvider implements SocialProvider
{
    public function __construct(
        private readonly ApifyClient $apify,
        private readonly string $actorId = 'clockworks/tiktok-profile-scraper',
    ) {}

    public function name(): string
    {
        return 'tiktok';
    }

    public function collect(array $accounts): array
    {
        $out = [];
        foreach ($accounts as $account) {
            $handle = $this->handle((string) $account);
            if ($handle === '') {
                continue;
            }
            try {
                $items = $this->apify->runActor($this->actorId, [
                    'profiles'             => [$handle],
                    'resultsPerPage'       => 1,
                    'shouldDownloadVideos' => false,
                    'shouldDownloadCovers' => false,
                ]);
            } catch (\Throwable) {
                continue;
            }
            $item = $items[0] ?? null;
            if (!is_array($item)) {
                continue;
            }
            $stats = $item['authorMeta'] ?? $item; // je nach Actor liegt es unter authorMeta
            $out[] = new SocialMetric(
                platform:   'tiktok',
                account:    $handle,
                followers:  $this->pick($stats, ['fans', 'followerCount', 'followers']),
                viewsTotal: $this->pick($stats, ['heart', 'hearts', 'likes', 'videoViews', 'playCount']),
                postsTotal: $this->pick($stats, ['video', 'videoCount', 'videos']),
                source:     'apify',
            );
        }
        return $out;
    }

    /** Extrahiert den @handle aus URL oder rohem Namen. */
    private function handle(string $account): string
    {
        if (preg_match('#@([\w.-]+)#', $account, $m)) {
            return $m[1];
        }
        return ltrim(trim($account), '@');
    }

    /**
     * Liest den ersten vorhandenen Schlüssel aus mehreren Kandidaten (Actors variieren).
     * @param array<string,mixed> $data
     * @param array<int,string> $keys
     */
    private function pick(array $data, array $keys): ?int
    {
        foreach ($keys as $k) {
            if (isset($data[$k]) && is_numeric($data[$k])) {
                return (int) $data[$k];
            }
        }
        return null;
    }
}
