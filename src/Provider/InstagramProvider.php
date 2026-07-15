<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Instagram-Sichtbarkeit der EIGENEN Kunden-Accounts via Apify (Profil-Scraper).
 * Nur eigene, öffentliche Accounts (Follower, Post-Anzahl) — kein Wettbewerber-Tracking.
 *
 * Hinweis: Instagram gibt öffentlich KEINE aggregierten Account-Views her (nur Follower,
 * Post-Anzahl, und Likes/Comments je einzelnem Post). viewsTotal bleibt daher i.d.R. null.
 * Feldnamen beim ersten echten Lauf verifizieren. Default-Actor:
 * apify/instagram-profile-scraper (offiziell gepflegt).
 */
final class InstagramProvider implements SocialProvider
{
    public function __construct(
        private readonly ApifyClient $apify,
        private readonly string $actorId = 'apify/instagram-profile-scraper',
    ) {}

    public function name(): string
    {
        return 'instagram';
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
                    'usernames' => [$handle],
                ]);
            } catch (\Throwable) {
                continue;
            }
            $item = $items[0] ?? null;
            if (!is_array($item)) {
                continue;
            }
            $out[] = new SocialMetric(
                platform:   'instagram',
                account:    $handle,
                followers:  $this->pick($item, ['followersCount', 'followers']),
                viewsTotal: null, // IG liefert öffentlich keine aggregierten Account-Views
                postsTotal: $this->pick($item, ['postsCount', 'mediaCount', 'posts']),
                source:     'apify',
            );
        }
        return $out;
    }

    private function handle(string $account): string
    {
        if (preg_match('#instagram\.com/([\w.-]+)#', $account, $m)) {
            return $m[1];
        }
        return ltrim(trim($account), '@');
    }

    /**
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
