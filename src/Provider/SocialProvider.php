<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Erhebt Social-Media-Kennzahlen (Follower, Views) der EIGENEN Kunden-Kanäle einer
 * Plattform über einen öffentlichen Zugang (ohne OAuth). Implementierung: YouTube
 * (Data API, API-Key). Für echte Monats-Views der verbundenen Kanäle s.
 * ConnectedSocialProvider (OAuth). Kein Wettbewerber-Tracking.
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
