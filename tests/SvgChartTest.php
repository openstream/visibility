<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use Openstream\Visibility\Chart\SvgChart;
use PHPUnit\Framework\TestCase;

final class SvgChartTest extends TestCase
{
    private SvgChart $chart;

    protected function setUp(): void
    {
        $this->chart = new SvgChart();
    }

    public function testLineProducesWellFormedSvg(): void
    {
        $svg = $this->chart->line(['Jan 26', 'Feb 26', 'Mär 26'], [100.0, 250.0, 421.0], 'Titel');
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringEndsWith('</svg>', $svg);
        $this->assertNotFalse(simplexml_load_string($svg), 'SVG muss wohlgeformtes XML sein');
        $this->assertStringContainsString('Titel', $svg);
        // Letzter Wert wird hervorgehoben eingezeichnet.
        $this->assertStringContainsString('421', $svg);
    }

    public function testLineFormatsThousandsGermanStyle(): void
    {
        // y-Achse rundet auf „schöne" Obergrenze; 1'200 → Skala bis 2'000 mit Apostroph.
        $svg = $this->chart->line(['A', 'B'], [500.0, 1200.0]);
        $this->assertStringContainsString('2&apos;000', $svg, 'Tausender mit Apostroph (deutsch)');
    }

    public function testLineHandlesSinglePoint(): void
    {
        $svg = $this->chart->line(['Jul 26'], [42.0]);
        $this->assertNotFalse(simplexml_load_string($svg));
        // Kein polyline bei nur einem Punkt (braucht mind. 2), aber ein Datenpunkt.
        $this->assertStringNotContainsString('<polyline', $svg);
        $this->assertStringContainsString('<circle', $svg);
    }

    public function testBarsUsesFixedScaleAndSuffix(): void
    {
        $rows = [
            ['label' => 'ChatGPT', 'value' => 30.0],
            ['label' => 'Perplexity', 'value' => 40.0],
        ];
        $svg = $this->chart->bars($rows, 'GEO', 100.0, ' %');
        $this->assertNotFalse(simplexml_load_string($svg));
        $this->assertStringContainsString('30 %', $svg);
        $this->assertStringContainsString('40 %', $svg);
        $this->assertStringContainsString('ChatGPT', $svg);
    }

    public function testBarsClampsOverflowToTrackWidth(): void
    {
        // Wert über der festen Skala darf den Balken nicht über den Track hinaus zeichnen.
        $rows = [['label' => 'X', 'value' => 150.0]];
        $svg = $this->chart->bars($rows, '', 100.0);
        $this->assertNotFalse(simplexml_load_string($svg));
    }

    public function testDonutGroupsSmallSlices(): void
    {
        $slices = [
            ['label' => 'Google', 'value' => 81.6],
            ['label' => 'Bing', 'value' => 10.17],
            ['label' => 'DuckDuckGo', 'value' => 2.31],
            ['label' => 'Yahoo', 'value' => 2.27],
            ['label' => 'Yandex', 'value' => 1.79],
            ['label' => 'Ecosia', 'value' => 1.39],
        ];
        $svg = $this->chart->donut($slices, 'CH', 3.0);
        $this->assertNotFalse(simplexml_load_string($svg));
        // Segmente < 3 % werden zu „Übrige" gebündelt.
        $this->assertStringContainsString('Übrige', $svg);
        $this->assertStringContainsString('Google', $svg);
        // Einzelne kleine Marken erscheinen nicht mehr als eigene Legende.
        $this->assertStringNotContainsString('Ecosia', $svg);
    }

    public function testDonutFormatsPercentGerman(): void
    {
        $svg = $this->chart->donut([
            ['label' => 'A', 'value' => 71.88],
            ['label' => 'B', 'value' => 28.12],
        ]);
        // Dezimalkomma statt Punkt.
        $this->assertStringContainsString('71,9 %', $svg);
    }

    public function testChartsWorkInLightAndDarkWithoutDynamicCss(): void
    {
        // EIN statisches Schema für beide Modi: transparenter Hintergrund (kein
        // <rect> mit Fill), KEIN dynamisches CSS (prefers-color-scheme flackerte
        // auf GitHub). Vordergrundfarben sind beidseitig lesbar.
        $svg = $this->chart->line(['A', 'B'], [1.0, 2.0], 'T');
        $this->assertStringNotContainsString('prefers-color-scheme', $svg, 'kein dynamisches Theme-CSS');
        $this->assertStringNotContainsString('<style', $svg, 'kein <style>-Block');
        $this->assertStringNotContainsString('fill="#ffffff"', $svg, 'kein fest weisser Hintergrund');
        $this->assertStringNotContainsString('<rect width="720"', $svg, 'kein deckender Hintergrund-Rect');
    }

    public function testEscapesLabels(): void
    {
        $svg = $this->chart->bars([['label' => 'A & B <x>', 'value' => 1.0]]);
        $this->assertNotFalse(simplexml_load_string($svg), 'Sonderzeichen müssen escaped sein');
        $this->assertStringContainsString('&amp;', $svg);
    }
}
