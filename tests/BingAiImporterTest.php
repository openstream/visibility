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
}
