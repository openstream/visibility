<?php

declare(strict_types=1);

namespace Openstream\Visibility\Onboarding;

/**
 * Liest den CSV-Export des Bing "AI Performance"-Reports (bing.com/webmasters/
 * aiperformance). Da der Report Beta ist und keine API hat, ist CSV-Import der
 * einzige Weg. Format ist nicht stabil dokumentiert → spaltentolerant: die
 * Grounding-Query-Spalte wird über den Header erkannt, nicht über eine feste Position.
 *
 * Zwei Verwendungen:
 *  - Grounding Queries = echte KI-Fragen, bei denen die Seite zitiert wurde
 *    → wertvolles GEO-Prompt-Saatgut fürs Onboarding.
 *  - Citations/Counts → später laufende GEO-Messung (ai_mentions).
 */
final class BingAiImporter
{
    /** Mögliche Header-Namen für die Grounding-Query-Spalte (case-insensitive, Teilstring). */
    private const QUERY_HEADERS = ['grounding quer', 'query', 'anfrage', 'frage', 'suchanfrage'];

    /** Header-Kandidaten für die Zitations-Anzahl bzw. den Zitations-Anteil. */
    private const CITATION_HEADERS = ['citation', 'zitat'];
    private const SHARE_HEADERS    = ['share', 'anteil'];

    /**
     * Liest die Bing-AI-CSV als Zitations-Datensätze für die laufende GEO-Messung.
     * Jede Zeile ist eine Grounding Query, bei der die Domain in Copilot/Bing-AI
     * ZITIERT wurde (Microsoft liefert keine „nicht zitiert"-Zeilen). Entsprechend
     * ist jede Zeile mentioned=cited=true; eine Sichtbarkeits-RATE gibt es hier nicht.
     *
     * @return array<int,array{query:string,citations:int,share:?string}>
     */
    public function citations(string $csvPath): array
    {
        $rows = $this->readCsv($csvPath);
        if (!$rows) {
            return [];
        }
        $header = array_shift($rows);
        $qCol = $this->findColumn($header, self::QUERY_HEADERS);
        if ($qCol === null) {
            throw new \RuntimeException(
                'Keine Grounding-Query-Spalte in der CSV gefunden. Header: ' . implode(', ', $header)
            );
        }
        $cCol = $this->findColumn($header, self::CITATION_HEADERS);
        $sCol = $this->findColumn($header, self::SHARE_HEADERS);

        $out = [];
        foreach ($rows as $r) {
            $q = trim((string) ($r[$qCol] ?? ''));
            if ($q === '') {
                continue;
            }
            $out[] = [
                'query'     => $q,
                'citations' => $cCol !== null ? (int) preg_replace('/\D/', '', (string) ($r[$cCol] ?? '')) : 0,
                'share'     => $sCol !== null ? (trim((string) ($r[$sCol] ?? '')) ?: null) : null,
            ];
        }
        return $out;
    }

    /**
     * Liest den AIPerformanceOverviewStats-Report (Tageszeitreihe: Datum, Citations,
     * Cited Pages) und aggregiert ihn zur Monatssumme. Das ist die ECHTE Gesamtzahl der
     * Citations im Monat (nicht nur die Summe der Top-Grounding-Queries).
     *
     * @return array{citations_total:int,cited_pages_peak:int,days:int}
     */
    public function overviewTotals(string $csvPath): array
    {
        $rows = $this->readCsv($csvPath);
        if (!$rows) {
            return ['citations_total' => 0, 'cited_pages_peak' => 0, 'days' => 0];
        }
        $header = array_shift($rows);
        $cCol = $this->findColumn($header, self::CITATION_HEADERS);
        $pCol = $this->findColumn($header, ['cited page', 'pages', 'seiten']);

        $total = 0;
        $peak = 0;
        $days = 0;
        foreach ($rows as $r) {
            if ($cCol === null || trim((string) ($r[$cCol] ?? '')) === '') {
                continue;
            }
            $total += (int) preg_replace('/\D/', '', (string) ($r[$cCol] ?? ''));
            if ($pCol !== null) {
                $peak = max($peak, (int) preg_replace('/\D/', '', (string) ($r[$pCol] ?? '')));
            }
            $days++;
        }
        return ['citations_total' => $total, 'cited_pages_peak' => $peak, 'days' => $days];
    }

    /**
     * Liest den AIPageStatsReport (Seite, Citations): welche Seiten der Domain in
     * KI-Antworten zitiert werden, mit Citation-Anzahl. Absteigend sortiert.
     *
     * @return array<int,array{page:string,citations:int}>
     */
    public function pageStats(string $csvPath): array
    {
        $rows = $this->readCsv($csvPath);
        if (!$rows) {
            return [];
        }
        $header = array_shift($rows);
        $pCol = $this->findColumn($header, ['seite', 'page', 'url']);
        $cCol = $this->findColumn($header, self::CITATION_HEADERS);
        if ($pCol === null) {
            throw new \RuntimeException('Keine Seiten-Spalte im PageStats-Report. Header: ' . implode(', ', $header));
        }

        $out = [];
        foreach ($rows as $r) {
            $page = trim((string) ($r[$pCol] ?? ''));
            if ($page === '') {
                continue;
            }
            $out[] = [
                'page'      => $page,
                'citations' => $cCol !== null ? (int) preg_replace('/\D/', '', (string) ($r[$cCol] ?? '')) : 0,
            ];
        }
        usort($out, static fn($a, $b) => $b['citations'] <=> $a['citations']);
        return $out;
    }

    /**
     * Extrahiert die Grounding Queries aus einer Bing-AI-CSV.
     *
     * @return array<int,string> eindeutige Grounding-Query-Strings (Reihenfolge erhalten)
     */
    public function groundingQueries(string $csvPath): array
    {
        $rows = $this->readCsv($csvPath);
        if (!$rows) {
            return [];
        }
        $header = array_shift($rows);
        $col = $this->findColumn($header, self::QUERY_HEADERS);
        if ($col === null) {
            throw new \RuntimeException(
                'Keine Grounding-Query-Spalte in der CSV gefunden. Header: ' . implode(', ', $header)
            );
        }

        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $q = trim((string) ($r[$col] ?? ''));
            if ($q === '') {
                continue;
            }
            $k = mb_strtolower($q);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $q;
            }
        }
        return $out;
    }

    /**
     * Findet den Spaltenindex, dessen Header einen der Kandidaten (Teilstring) enthält.
     *
     * @param array<int,string> $header
     * @param array<int,string> $candidates
     */
    private function findColumn(array $header, array $candidates): ?int
    {
        foreach ($header as $i => $name) {
            $h = mb_strtolower(trim((string) $name));
            foreach ($candidates as $c) {
                if (str_contains($h, $c)) {
                    return $i;
                }
            }
        }
        return null;
    }

    /** @return array<int,array<int,string>> */
    private function readCsv(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Bing-AI-CSV nicht gefunden: {$path}");
        }
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException("CSV nicht lesbar: {$path}");
        }
        // BOM entfernen, Trennzeichen (Komma/Semikolon) autodetektieren anhand erster Zeile.
        $first = fgets($fh);
        if ($first === false) {
            fclose($fh);
            return [];
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delim = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';

        $rows = [str_getcsv(rtrim($first, "\r\n"), $delim)];
        while (($line = fgetcsv($fh, 0, $delim)) !== false) {
            $rows[] = $line;
        }
        fclose($fh);
        return $rows;
    }
}
