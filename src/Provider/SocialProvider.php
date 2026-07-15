<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Erhebt Social-Media-Kennzahlen (Follower, Views) der EIGENEN Kunden-Kanäle einer
 * Plattform. Implementierungen: YouTube (offizielle Data API), TikTok/Instagram (Apify,
 * nur eigene Accounts). Kein Wettbewerber-Tracking.
 */
interface SocialProvider
{
    /**
     * @param array<int,string> $accounts Kanal-IDs/Handles/URLs der eigenen Kanäle
     * @return array<int,SocialMetric>
     */
    public function collect(array $accounts): array;

    /** Plattform-Name (youtube|tiktok|instagram) für Logging/Zuordnung. */
    public function name(): string;
}
