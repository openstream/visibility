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
