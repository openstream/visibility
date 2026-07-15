<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use Openstream\Visibility\Provider\MentionAnalyzer;
use PHPUnit\Framework\TestCase;

final class MentionAnalyzerTest extends TestCase
{
    private function analyzer(): MentionAnalyzer
    {
        return new MentionAnalyzer(
            'openstream.ch',
            ['Openstream', 'The Openstream'],
            ['konkurrent.ch', 'Mitbewerber AG'],
        );
    }

    public function testDetectsBrandMention(): void
    {
        $r = $this->analyzer()->analyze('Openstream ist eine Schweizer Analyse-Plattform.', []);
        $this->assertTrue($r['mentioned']);
        $this->assertSame(1, $r['position']); // ganz vorne im Text
    }

    public function testDetectsDomainMention(): void
    {
        $r = $this->analyzer()->analyze('Mehr Infos auf openstream.ch gibt es dazu.', []);
        $this->assertTrue($r['mentioned']);
    }

    public function testNotMentionedWhenAbsent(): void
    {
        $r = $this->analyzer()->analyze('Es gibt viele Anbieter in der Schweiz.', []);
        $this->assertFalse($r['mentioned']);
        $this->assertFalse($r['cited']);
        $this->assertNull($r['position']);
    }

    public function testCitedWhenDomainInCitationUrl(): void
    {
        $r = $this->analyzer()->analyze('Diverse Anbieter existieren.', ['https://www.openstream.ch/vergleich/']);
        $this->assertTrue($r['cited']);
    }

    public function testCaseInsensitiveBrand(): void
    {
        $r = $this->analyzer()->analyze('Die Firma OPENSTREAM bietet Analysen.', []);
        $this->assertTrue($r['mentioned']);
    }

    public function testFindsCompetitors(): void
    {
        $text = 'Alternativen sind konkurrent.ch und die Mitbewerber AG.';
        $r = $this->analyzer()->analyze($text, []);
        $this->assertContains('konkurrent.ch', $r['competitors']);
        $this->assertContains('Mitbewerber AG', $r['competitors']);
    }

    public function testPositionLaterInTextIsHigherRank(): void
    {
        // Marke erst im letzten Drittel → Rang 3
        $text = str_repeat('Vorspann Text. ', 40) . 'Schliesslich noch Openstream.';
        $r = $this->analyzer()->analyze($text, []);
        $this->assertSame(3, $r['position']);
    }
}
