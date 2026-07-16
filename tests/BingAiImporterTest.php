<?php

declare(strict_types=1);

namespace Openstream\Visibility\Tests;

use Openstream\Visibility\Onboarding\BingAiImporter;
use PHPUnit\Framework\TestCase;

final class BingAiImporterTest extends TestCase
{
    private function tmpCsv(string $content): string
    {
        $path = sys_get_temp_dir() . '/bingai_' . uniqid() . '.csv';
        file_put_contents($path, $content);
        return $path;
    }

    public function testExtractsGroundingQueriesFromCommaCsv(): void
    {
        $csv = $this->tmpCsv("Grounding Query,Citations\nwer baut shopify shops in zürich,3\nwordpress agentur schweiz,1\n");
        $out = (new BingAiImporter())->groundingQueries($csv);
        unlink($csv);
        $this->assertSame(['wer baut shopify shops in zürich', 'wordpress agentur schweiz'], $out);
    }

    public function testHandlesSemicolonDelimiterAndBom(): void
    {
        $csv = $this->tmpCsv("\xEF\xBB\xBFQuery;Impressions\nki anbieter schweiz;5\n");
        $out = (new BingAiImporter())->groundingQueries($csv);
        unlink($csv);
        $this->assertSame(['ki anbieter schweiz'], $out);
    }

    public function testDeduplicatesCaseInsensitive(): void
    {
        $csv = $this->tmpCsv("Grounding Query\nMagento Agentur\nmagento agentur\n");
        $out = (new BingAiImporter())->groundingQueries($csv);
        unlink($csv);
        $this->assertSame(['Magento Agentur'], $out); // erste Schreibweise bleibt, Dublette raus
    }

    public function testDetectsColumnByPartialHeaderName(): void
    {
        // Spalte heisst nur "Suchanfrage" — muss trotzdem erkannt werden.
        $csv = $this->tmpCsv("Datum,Suchanfrage,Klicks\n2026-07-01,shopify migration schweiz,0\n");
        $out = (new BingAiImporter())->groundingQueries($csv);
        unlink($csv);
        $this->assertSame(['shopify migration schweiz'], $out);
    }

    public function testThrowsWhenNoQueryColumn(): void
    {
        $csv = $this->tmpCsv("Datum,Klicks\n2026-07-01,3\n");
        $this->expectException(\RuntimeException::class);
        try {
            (new BingAiImporter())->groundingQueries($csv);
        } finally {
            unlink($csv);
        }
    }

    public function testEmptyFileYieldsEmpty(): void
    {
        $csv = $this->tmpCsv('');
        $out = (new BingAiImporter())->groundingQueries($csv);
        unlink($csv);
        $this->assertSame([], $out);
    }

    public function testCitationsParsesRealExportFormat(): void
    {
        // Echtes Export-Format: BOM, gequotete Felder, Prozent-Anteil mit Komma.
        $csv = $this->tmpCsv(
            "\xEF\xBB\xBF\"Grounding Query\",\"Intent\",\"Topic\",\"Citations\",\"Citation Share\"\n"
            . "\"stablecoins kaufen schweiz\",\"Commercial\",\"Crypto\",\"46\",\"35,38%\"\n"
            . "\"ai plattformen\",\"Informational\",\"AI\",\"42\",\"14,19%\"\n"
        );
        $out = (new BingAiImporter())->citations($csv);
        unlink($csv);

        $this->assertCount(2, $out);
        $this->assertSame('stablecoins kaufen schweiz', $out[0]['query']);
        $this->assertSame(46, $out[0]['citations']);
        $this->assertSame('35,38%', $out[0]['share']);
        $this->assertSame(42, $out[1]['citations']);
    }

    public function testCitationsStripsThousandSeparators(): void
    {
        // "1.234" bzw. "1'234" Citations müssen als 1234 gelesen werden.
        $csv = $this->tmpCsv("Grounding Query,Citations\nviel zitierte frage,\"1'234\"\n");
        $out = (new BingAiImporter())->citations($csv);
        unlink($csv);
        $this->assertSame(1234, $out[0]['citations']);
    }

    public function testCitationsWithoutCountColumnDefaultsToZero(): void
    {
        $csv = $this->tmpCsv("Grounding Query\nnur eine frage\n");
        $out = (new BingAiImporter())->citations($csv);
        unlink($csv);
        $this->assertSame(0, $out[0]['citations']);
        $this->assertNull($out[0]['share']);
    }
}
