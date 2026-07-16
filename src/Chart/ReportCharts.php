<?php

declare(strict_types=1);

namespace Openstream\Visibility\Chart;

use Symfony\Component\Yaml\Yaml;

/**
 * Erzeugt die Report-Diagramme als SVG-Dateien unter `<reportDir>/charts/` und
 * liefert die relative Markdown-Einbettung zurück (`![Alt](charts/x.svg)`).
 *
 * Bewusst als eigener Layer neben dem ReportBuilder: der Builder liefert die
 * Daten (aus dem Repository), diese Klasse macht daraus Bilder. So bleibt der
 * Text-Report auch ohne Charts baubar (z. B. in Tests), und die Chart-Logik
 * ist an einer Stelle.
 *
 * Jede Methode gibt Markdown zurück (leerer String, wenn die Datenlage kein
 * sinnvolles Diagramm hergibt) und legt als Seiteneffekt die SVG-Datei an.
 */
final class ReportCharts
{
    private SvgChart $svg;
    private string $chartsDir;

    /** @param string $reportDir Verzeichnis des .md-Reports (charts/ liegt darunter) */
    public function __construct(private readonly string $reportDir)
    {
        $this->svg = new SvgChart();
        $this->chartsDir = rtrim($reportDir, '/') . '/charts';
    }

    /**
     * Zeitreihe: geschätzte Sichtbarkeit (ETV) je Monat.
     * @param array<int,array<string,mixed>> $history aus ClientRepository::visibilityHistory()
     */
    public function visibilityTrend(array $history): string
    {
        if (count($history) < 2) {
            return '';
        }
        $labels = array_map(fn($r) => $this->monthShort((string) $r['period']), $history);
        $values = array_map(static fn($r) => (float) $r['etv'], $history);

        $svg = $this->svg->line($labels, $values, 'Geschätzte Google-Sichtbarkeit (ETV) je Monat');
        return $this->write('visibility-trend', $svg, 'Sichtbarkeits-Verlauf (ETV je Monat)');
    }

    /**
     * Zeitreihe: Openstream Visibility Score (aktive Sichtkontakte) je Monat.
     * @param array<int,array{period:string,score:int}> $history
     */
    public function ovsTrend(array $history): string
    {
        if (count($history) < 2) {
            return '';
        }
        $labels = array_map(fn($r) => $this->monthShort((string) $r['period']), $history);
        $values = array_map(static fn($r) => (float) $r['score'], $history);

        $svg = $this->svg->line($labels, $values, 'Sichtbarkeits-Score (aktive Sichtkontakte) je Monat');
        return $this->write('ovs-trend', $svg, 'OVS-Verlauf je Monat');
    }

    /**
     * Momentaufnahme: Keyword-Verteilung über Positions-Buckets (letzter Monat).
     * @param array<string,mixed>|null $latest jüngste visibility_history-Zeile
     */
    public function rankingDistribution(?array $latest): string
    {
        if (!$latest) {
            return '';
        }
        $buckets = [
            'Platz 1'      => (int) ($latest['pos_1'] ?? 0),
            'Platz 2–3'    => (int) ($latest['pos_2_3'] ?? 0),
            'Platz 4–10'   => (int) ($latest['pos_4_10'] ?? 0),
            'Platz 11–20'  => (int) ($latest['pos_11_20'] ?? 0),
            'Platz 21–50'  => (int) ($latest['pos_21_50'] ?? 0),
            'Platz 51–100' => (int) ($latest['pos_51_100'] ?? 0),
        ];
        if (array_sum($buckets) === 0) {
            return '';
        }
        $rows = [];
        foreach ($buckets as $label => $count) {
            $rows[] = ['label' => $label, 'value' => (float) $count];
        }
        $svg = $this->svg->bars($rows, 'Rankende Keywords nach Google-Position');
        return $this->write('ranking-distribution', $svg, 'Keyword-Verteilung nach Position');
    }

    /**
     * Momentaufnahme: GEO-Erwähnungsrate je KI-Kanal.
     * @param array<string,array{prompts:int,mentioned:int,cited:int}> $geoSummary
     */
    public function geoVisibility(array $geoSummary): string
    {
        $labels = ['chatgpt' => 'ChatGPT', 'gemini' => 'Gemini', 'claude' => 'Claude',
            'perplexity' => 'Perplexity', 'ai_overview' => 'Google AI Overview', 'bing_ai' => 'Copilot / Bing-AI'];
        $rows = [];
        foreach ($labels as $engine => $label) {
            if (!isset($geoSummary[$engine]) || $geoSummary[$engine]['prompts'] === 0) {
                continue;
            }
            $s = $geoSummary[$engine];
            $rate = round($s['mentioned'] / $s['prompts'] * 100);
            $rows[] = ['label' => $label, 'value' => (float) $rate];
        }
        if (!$rows) {
            return '';
        }
        $svg = $this->svg->bars($rows, 'KI-Sichtbarkeit: Erwähnungsrate je Kanal', 100.0, ' %');
        return $this->write('geo-visibility', $svg, 'KI-Sichtbarkeit je Kanal');
    }

    /** Momentaufnahme: CH-Marktanteile Suchmaschinen (Donut) aus der Markt-Config. */
    public function marketSearchEngines(string $marketYaml): string
    {
        if (!is_file($marketYaml)) {
            return '';
        }
        $m = Yaml::parseFile($marketYaml);
        $slices = array_map(
            static fn($s) => ['label' => (string) $s['name'], 'value' => (float) $s['share']],
            $m['search_engines'] ?? []
        );
        if (!$slices) {
            return '';
        }
        $svg = $this->svg->donut($slices, 'Suchmaschinen-Marktanteile Schweiz');
        return $this->write('market-search', $svg, 'CH-Marktanteile Suchmaschinen');
    }

    /** Momentaufnahme: CH-Marktanteile KI-Assistenten (Donut) aus der Markt-Config. */
    public function marketAiAssistants(string $marketYaml): string
    {
        if (!is_file($marketYaml)) {
            return '';
        }
        $m = Yaml::parseFile($marketYaml);
        $slices = array_map(
            static fn($a) => ['label' => (string) $a['name'], 'value' => (float) $a['share']],
            $m['ai_assistants'] ?? []
        );
        if (!$slices) {
            return '';
        }
        $svg = $this->svg->donut($slices, 'KI-Assistenten-Marktanteile Schweiz');
        return $this->write('market-ai', $svg, 'CH-Marktanteile KI-Assistenten');
    }

    /** Schreibt das SVG und gibt die relative Markdown-Einbettung zurück. */
    private function write(string $name, string $svg, string $alt): string
    {
        if (!is_dir($this->chartsDir)) {
            mkdir($this->chartsDir, 0775, true);
        }
        file_put_contents("{$this->chartsDir}/{$name}.svg", $svg);
        return sprintf("![%s](charts/%s.svg)\n\n", $alt, $name);
    }

    private function monthShort(string $period): string
    {
        $months = [1 => 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul',
            'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
        [$y, $m] = explode('-', $period);
        return ($months[(int) $m] ?? $m) . ' ' . substr($y, 2);
    }
}
