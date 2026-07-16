<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use Openstream\Visibility\Provider\GscClient;
use PHPUnit\Framework\TestCase;

final class GscBucketTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function rows(): array
    {
        return [
            ['keys' => ['a'], 'position' => 1.0, 'impressions' => 500],
            ['keys' => ['b'], 'position' => 1.2, 'impressions' => 1],   // #1 aber Long-Tail
            ['keys' => ['c'], 'position' => 2.8, 'impressions' => 40],
            ['keys' => ['d'], 'position' => 7.0, 'impressions' => 200],
            ['keys' => ['e'], 'position' => 15.0, 'impressions' => 30],
            ['keys' => ['f'], 'position' => 60.0, 'impressions' => 5],
        ];
    }

    public function testBucketsWithoutThreshold(): void
    {
        $b = GscClient::bucketByPosition($this->rows(), 0);
        $this->assertSame(6, $b['total']);
        $this->assertSame(6, $b['relevant']);
        $this->assertSame(2, $b['pos_1']);      // 1.0 + 1.2
        $this->assertSame(1, $b['pos_2_3']);    // 2.8
        $this->assertSame(1, $b['pos_4_10']);   // 7.0
        $this->assertSame(1, $b['pos_11_20']);  // 15.0
        $this->assertSame(1, $b['pos_51_100']); // 60.0
    }

    public function testMinImpressionsFiltersLongTail(): void
    {
        // Schwelle 10: die 1-Impression-#1 (b) und die 5-Impression-Zeile (f) fallen raus.
        $b = GscClient::bucketByPosition($this->rows(), 10);
        $this->assertSame(6, $b['total']);      // total zählt alle
        $this->assertSame(4, $b['relevant']);   // nur die über der Schwelle
        $this->assertSame(1, $b['pos_1']);      // nur die 500-Impr-#1, nicht die 1-Impr
        $this->assertSame(0, $b['pos_51_100']); // die 5-Impr-Zeile raus
    }
}
