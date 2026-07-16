<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Ein normalisierter Social-Media-Messwert der EIGENEN Kunden-Kanäle (eine Zeile in
 * `social_metrics`). measured_at wird beim Schreiben gesetzt (Zeitreihe).
 *
 * viewsTotal ist der kumulierte Lifetime-Zähler der Plattform; die Monats-Views leitet
 * der Report aus der Differenz zweier Stände ab.
 */
final class SocialMetric
{
    public function __construct(
        public readonly string $platform,     // youtube | tiktok | instagram
        public readonly string $account,      // Kanal-ID/Handle/URL (Anzeige + Zuordnung)
        public readonly ?int $followers,      // Subscriber/Follower
        public readonly ?int $viewsTotal,     // kumulierte Lifetime-Views (Data API; Monats-Views via Delta)
        public readonly ?int $postsTotal,     // Anzahl Videos/Posts (falls verfügbar)
        public readonly string $source,       // youtube_data_api | youtube_analytics | instagram_graph | tiktok_api
        public readonly ?int $monthlyViews = null, // echte Monats-Views (Analytics/Insights); sonst null
    ) {}
}
