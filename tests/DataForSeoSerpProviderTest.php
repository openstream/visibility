<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use Openstream\Visibility\Provider\DataForSeoSerpProvider;
use PHPUnit\Framework\TestCase;

final class DataForSeoSerpProviderTest extends TestCase
{
    public function testDomainNeedleStripsSchemeWwwAndSlash(): void
    {
        $this->assertSame('openstream.ch', DataForSeoSerpProvider::domainNeedle('https://www.openstream.ch/'));
        $this->assertSame('openstream.ch', DataForSeoSerpProvider::domainNeedle('http://openstream.ch'));
        $this->assertSame('openstream.ch', DataForSeoSerpProvider::domainNeedle('OpenStream.CH'));
    }

    public function testFindDomainReturnsPositionAndUrl(): void
    {
        $items = [
            ['type' => 'organic', 'domain' => 'konkurrent.ch', 'rank_absolute' => 1, 'url' => 'https://konkurrent.ch/a'],
            ['type' => 'featured_snippet', 'domain' => 'openstream.ch', 'rank_absolute' => 2, 'url' => 'x'], // kein organic → ignoriert
            ['type' => 'organic', 'domain' => 'www.openstream.ch', 'rank_absolute' => 4, 'url' => 'https://www.openstream.ch/vergleich/'],
        ];
        [$pos, $url] = DataForSeoSerpProvider::findDomain($items, 'openstream.ch');
        $this->assertSame(4.0, $pos);
        $this->assertSame('https://www.openstream.ch/vergleich/', $url);
    }

    public function testFindDomainReturnsNullWhenAbsent(): void
    {
        $items = [
            ['type' => 'organic', 'domain' => 'konkurrent.ch', 'rank_absolute' => 1, 'url' => 'x'],
        ];
        [$pos, $url] = DataForSeoSerpProvider::findDomain($items, 'openstream.ch');
        $this->assertNull($pos);
        $this->assertNull($url);
    }

    public function testFindDomainFirstOrganicMatchWins(): void
    {
        $items = [
            ['type' => 'organic', 'domain' => 'openstream.ch', 'rank_absolute' => 3, 'url' => 'first'],
            ['type' => 'organic', 'domain' => 'openstream.ch', 'rank_absolute' => 8, 'url' => 'second'],
        ];
        [$pos, $url] = DataForSeoSerpProvider::findDomain($items, 'openstream.ch');
        $this->assertSame(3.0, $pos);
        $this->assertSame('first', $url);
    }
}
