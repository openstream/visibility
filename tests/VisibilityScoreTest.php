<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use Openstream\Visibility\Report\VisibilityScore;
use PHPUnit\Framework\TestCase;

final class VisibilityScoreTest extends TestCase
{
    public function testImpressionsAreCtrWeightedNotAddedRaw(): void
    {
        // 45'168 Impressions × 0,28 % CTR ≈ 126 (nicht 45'168 naiv addiert).
        $r = VisibilityScore::compute([
            'google_clicks' => 126,
            'google_impressions' => 45168,
            'google_ctr' => 0.28,
        ]);
        $this->assertSame(126, $r['components']['google_impressionen_ctr']); // 45168*0.0028≈126.47→126
        $this->assertSame(126 + 126, $r['score']);
        // Kern-Prüfung: der Impressions-Beitrag ist NICHT die rohe Impressions-Zahl.
        $this->assertLessThan(45168, $r['components']['google_impressionen_ctr']);
    }

    public function testSumsAllChannels(): void
    {
        $r = VisibilityScore::compute([
            'google_clicks' => 100,
            'bing_clicks' => 20,
            'google_impressions' => 10000,
            'google_ctr' => 1.0,          // → 100
            'geo_mentions' => 15,
            'social_views' => 42000,
            'newsletter_opens' => 460,
        ]);
        // 100 + 20 + 100 + 15 + 42000 + 460
        $this->assertSame(42695, $r['score']);
        $this->assertCount(6, $r['components']);
    }

    public function testZeroChannelsAreOmitted(): void
    {
        $r = VisibilityScore::compute(['google_clicks' => 50]);
        $this->assertSame(['google_klicks' => 50], $r['components']);
        $this->assertSame(50, $r['score']);
    }

    public function testEmptyInputScoresZero(): void
    {
        $r = VisibilityScore::compute([]);
        $this->assertSame(0, $r['score']);
        $this->assertSame([], $r['components']);
    }

    public function testNegativeValuesClampedToZero(): void
    {
        $r = VisibilityScore::compute(['google_clicks' => -5, 'social_views' => 10]);
        $this->assertSame(['social_views' => 10], $r['components']);
    }
}
