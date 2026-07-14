<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use Openstream\Visibility\Provider\GscClient;
use Openstream\Visibility\Provider\GscSerpProvider;
use PHPUnit\Framework\TestCase;

final class GscSerpProviderTest extends TestCase
{
    public function testMatchesKeywordsToQueriesAndConvertsCtr(): void
    {
        // Gemockter GSC-Client mit festen searchAnalytics-Zeilen (query, page).
        $gsc = $this->createMock(GscClient::class);
        $gsc->method('searchAnalytics')->willReturn([
            ['keys' => ['ki anbieter schweiz', 'https://www.openstream.ch/x'], 'position' => 2.42, 'impressions' => 19, 'clicks' => 1, 'ctr' => 0.0526],
            ['keys' => ['nicht getrackt', 'https://www.openstream.ch/y'], 'position' => 5.0, 'impressions' => 100, 'clicks' => 0, 'ctr' => 0.0],
        ]);

        $provider = new GscSerpProvider($gsc, 'https://www.openstream.ch/');
        $out = $provider->collect([10 => 'ki anbieter schweiz', 11 => 'kommt nicht vor']);

        // Nur das getrackte, in GSC vorkommende Keyword liefert einen Messwert.
        $this->assertCount(1, $out);
        $m = $out[0];
        $this->assertSame('google', $m->engine);
        $this->assertSame(10, $m->keywordId);
        $this->assertSame(2.42, $m->position);
        $this->assertSame(19, $m->impressions);
        $this->assertSame(1, $m->clicks);
        $this->assertSame('gsc', $m->source);
        // CTR wird von Anteil (0.0526) in Prozent (5.26) umgerechnet.
        $this->assertEqualsWithDelta(5.26, $m->ctr, 0.01);
    }

    public function testAggregatesPerQueryKeepingHighestImpressionRow(): void
    {
        $gsc = $this->createMock(GscClient::class);
        // Gleiche Query auf zwei Seiten — die Zeile mit mehr Impressionen gewinnt (URL-Zuordnung).
        $gsc->method('searchAnalytics')->willReturn([
            ['keys' => ['shopify agentur', '/a'], 'position' => 8.0, 'impressions' => 5, 'clicks' => 0, 'ctr' => 0.0],
            ['keys' => ['shopify agentur', '/b'], 'position' => 3.0, 'impressions' => 40, 'clicks' => 2, 'ctr' => 0.05],
        ]);
        $provider = new GscSerpProvider($gsc, 'https://www.openstream.ch/');
        $out = $provider->collect([1 => 'shopify agentur']);

        $this->assertCount(1, $out);
        $this->assertSame('/b', $out[0]->url);
        $this->assertSame(40, $out[0]->impressions);
    }

    public function testCaseInsensitiveMatch(): void
    {
        $gsc = $this->createMock(GscClient::class);
        $gsc->method('searchAnalytics')->willReturn([
            ['keys' => ['WordPress Agentur', '/x'], 'position' => 4.0, 'impressions' => 10, 'clicks' => 1, 'ctr' => 0.1],
        ]);
        $provider = new GscSerpProvider($gsc, 'https://www.openstream.ch/');
        $out = $provider->collect([7 => 'wordpress agentur']);
        $this->assertCount(1, $out);
        $this->assertSame(7, $out[0]->keywordId);
    }
}
