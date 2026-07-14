<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use Openstream\Visibility\Onboarding\KeywordCombiner;
use PHPUnit\Framework\TestCase;

final class KeywordCombinerTest extends TestCase
{
    public function testGeneratesExpectedPatterns(): void
    {
        $c = new KeywordCombiner();
        $kw = $c->combine(['shopify'], ['agentur'], ['zürich']);

        // Erwartete Muster: pur, +Rolle, +Rolle+Region, +Region
        $this->assertContains('shopify', $kw);
        $this->assertContains('shopify agentur', $kw);
        $this->assertContains('shopify agentur zürich', $kw);
        $this->assertContains('shopify zürich', $kw);
    }

    public function testLowercasesAndTrims(): void
    {
        $c = new KeywordCombiner();
        $kw = $c->combine(['  WordPress  '], ['Agentur'], ['Schweiz']);
        $this->assertContains('wordpress', $kw);
        $this->assertContains('wordpress agentur schweiz', $kw);
        // Keine doppelten Leerzeichen / kein Casing-Rest
        foreach ($kw as $k) {
            $this->assertSame($k, mb_strtolower($k));
            $this->assertStringNotContainsString('  ', $k);
        }
    }

    public function testDeduplicates(): void
    {
        $c = new KeywordCombiner();
        // 'shopify' als Plattform UND als (redundante) Region → keine Dublette
        $kw = $c->combine(['shopify', 'shopify'], [], []);
        $this->assertSame(['shopify'], $kw);
    }

    public function testCountMatchesPatternMath(): void
    {
        $c = new KeywordCombiner();
        // 2 Plattformen, 2 Rollen, 2 Regionen.
        // pro Plattform: 1 (pur) + 2 (rolle) + 4 (rolle×region) + 2 (region) = 9
        $kw = $c->combine(['a', 'b'], ['r1', 'r2'], ['g1', 'g2']);
        $this->assertCount(18, $kw);
    }

    public function testEmptyPlatformsYieldsEmpty(): void
    {
        $c = new KeywordCombiner();
        $this->assertSame([], $c->combine([], ['agentur'], ['zürich']));
    }

    public function testFromConfigReadsBlock(): void
    {
        $c = new KeywordCombiner();
        $cfg = [
            'keyword_combinations' => [
                'platforms' => ['magento'],
                'roles'     => ['migration'],
                'regions'   => ['schweiz'],
            ],
        ];
        $kw = $c->fromConfig($cfg);
        $this->assertContains('magento migration schweiz', $kw);
    }

    public function testFromConfigMissingBlockYieldsEmpty(): void
    {
        $c = new KeywordCombiner();
        $this->assertSame([], $c->fromConfig([]));
        $this->assertSame([], $c->fromConfig(['keyword_combinations' => ['platforms' => []]]));
    }
}
