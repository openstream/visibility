<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Ein normalisierter Ranking-Messwert (eine Zeile in `measurements`).
 * measured_at wird beim Schreiben gesetzt (Zeitreihe).
 */
final class Measurement
{
    public function __construct(
        public readonly string $engine,          // google | bing
        public readonly ?int $keywordId,
        public readonly ?float $position,
        public readonly ?string $url,
        public readonly ?int $impressions,
        public readonly ?int $clicks,
        public readonly ?float $ctr,
        public readonly string $source,          // gsc | dataforseo_serp | bing_wmt
    ) {}
}
