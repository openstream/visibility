<?php

declare(strict_types=1);

namespace Openstream\Visibility\Report;

use Openstream\Visibility\App;
use Openstream\Visibility\Database\ClientRepository;
use Symfony\Component\Yaml\Yaml;

/**
 * Erzeugt den ausführlichen Visibility-Report (`.md`, Deutsch) aus den erhobenen
 * Daten. Aktuell umgesetzt: Markt-Kontext CH + Suchmaschinen-Rankings (Google/Bing)
 * mit Momentaufnahme und Delta zum Vormonat. Onsite/Offsite/GEO werden transparent
 * als „noch nicht erhoben" ausgewiesen (kommen, sobald die Provider gebaut sind).
 */
final class ReportBuilder
{
    private const ENGINE_LABEL = ['google' => 'Google', 'bing' => 'Bing'];

    public function __construct(private readonly ClientRepository $repo) {}

    /**
     * Baut den Markdown-Report für einen Kunden und Berichtsmonat (YYYY-MM).
     * @param array<string,mixed> $cfg  Kunden-Config (für Name/Domain/Markt)
     */
    public function build(int $clientId, string $period, array $cfg): string
    {
        $client = $this->repo->client($clientId) ?? [];
        $name = $cfg['name'] ?? ($client['name'] ?? '');
        $domain = $cfg['domain'] ?? ($client['domain'] ?? '');
        $prevPeriod = date('Y-m', strtotime($period . '-01 -1 month'));

        $md  = "# Visibility-Report — {$name}\n\n";
        $md .= "**Domain:** {$domain}  \n";
        $md .= "**Berichtsmonat:** " . $this->monthLabel($period) . "  \n";
        $md .= "**Erstellt:** " . date('d.m.Y') . "\n\n";
        $md .= "---\n\n";

        $md .= $this->marketContext();
        $md .= $this->visibilityTrend($clientId, $period);
        $md .= $this->searchRankings($clientId, $period, $prevPeriod);
        $md .= $this->pendingSections();

        $md .= "\n---\n";
        $md .= "_Automatisch erstellt vom Visibility Dashboard. Datenquellen: Google Search "
            . "Console, Bing Webmaster Tools, DataForSEO._\n";

        return $md;
    }

    /** Markt-Kontext CH aus config/market/switzerland.yaml (Donut-Kandidat). */
    private function marketContext(): string
    {
        $file = App::get()->configPath('market/switzerland.yaml');
        if (!is_file($file)) {
            return '';
        }
        $m = Yaml::parseFile($file);
        $md = "## Markt-Kontext Schweiz\n\n";
        $md .= "_Warum welche Kanäle zählen — Marktanteile (Quelle: {$m['source']}, Stand "
            . "{$m['as_of']})._\n\n";

        $md .= "**Suchmaschinen:** ";
        $md .= implode(' · ', array_map(
            fn($s) => "{$s['name']} {$s['share']} %",
            array_slice($m['search_engines'] ?? [], 0, 4),
        )) . "\n\n";

        $md .= "**KI-Assistenten:** ";
        $md .= implode(' · ', array_map(
            fn($s) => "{$s['name']} {$s['share']} %",
            array_slice($m['ai_assistants'] ?? [], 0, 5),
        )) . "\n\n";

        return $md;
    }

    /** Sichtbarkeits-Verlauf (Google, historisch) — Sparkline + Tabelle + Trend. */
    private function visibilityTrend(int $clientId, string $period): string
    {
        $hist = $this->repo->visibilityHistory($clientId, 'google', $period);
        if (count($hist) < 2) {
            return ''; // ohne Verlauf (min. 2 Punkte) kein Trend-Abschnitt
        }

        $md = "## Sichtbarkeits-Verlauf (Google)\n\n";
        $md .= "_Geschätzte Sichtbarkeit (ETV) und Anzahl rankender Keywords je Monat "
            . "— rückwirkend aus DataForSEO. Zeigt den Trend, nicht nur die Momentaufnahme._\n\n";

        $etv = array_map(static fn($r) => (float) $r['etv'], $hist);
        $md .= "**Sichtbarkeit:** " . $this->sparkline($etv) . "  \n";
        $md .= "**Verlauf:**\n\n";
        $md .= "| Monat | Sichtbarkeit (ETV) | Keywords | Top-3 | Top-10 |\n|---|---:|---:|---:|---:|\n";
        foreach ($hist as $r) {
            $top3 = (int) $r['pos_1'] + (int) $r['pos_2_3'];
            $top10 = $top3 + (int) $r['pos_4_10'];
            $md .= '| ' . $this->monthShort($r['period'])
                . ' | ' . number_format((float) $r['etv'], 0, ',', '\'')
                . ' | ' . (int) $r['keywords_total']
                . ' | ' . $top3
                . ' | ' . $top10 . " |\n";
        }
        $md .= "\n";

        // Trend-Einordnung erster vs. letzter Monat.
        $first = $etv[0];
        $last = end($etv);
        if ($first > 0) {
            $pct = round(($last - $first) / $first * 100);
            $dir = $pct > 2 ? "gestiegen (▲ {$pct} %)" : ($pct < -2 ? 'gesunken (▼ ' . abs($pct) . ' %)' : 'stabil');
            $md .= "> **Trend:** Über den erfassten Zeitraum ist die geschätzte Google-Sichtbarkeit "
                . "**{$dir}** (von " . number_format($first, 0, ',', '\'') . ' auf '
                . number_format($last, 0, ',', '\'') . " ETV).\n\n";
        }

        return $md;
    }

    /** Einfache Text-Sparkline aus Unicode-Blöcken (bis echte Charts da sind). */
    private function sparkline(array $values): string
    {
        $blocks = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
        $min = min($values);
        $max = max($values);
        $range = $max - $min ?: 1;
        $out = '';
        foreach ($values as $v) {
            $idx = (int) round(($v - $min) / $range * (count($blocks) - 1));
            $out .= $blocks[$idx];
        }
        return $out . '  (' . number_format($min, 0, ',', '\'') . '–' . number_format($max, 0, ',', '\'') . ' ETV)';
    }

    /** Suchmaschinen-Rankings (Google + Bing) mit Delta zum Vormonat. */
    private function searchRankings(int $clientId, string $period, string $prevPeriod): string
    {
        $summary = $this->repo->rankingSummary($clientId, $period);
        $prevAvg = $this->repo->avgPositionByEngine($clientId, $prevPeriod);

        $md = "## 1. Suchmaschinen-Rankings\n\n";

        if (!$summary) {
            $md .= "> Für {$this->monthLabel($period)} liegen noch keine Ranking-Messwerte vor. "
                . "Nach dem ersten `collect`-Lauf erscheinen hier Positionen, Impressionen und Trends.\n\n";
            return $md;
        }

        foreach (['google', 'bing'] as $engine) {
            if (!isset($summary[$engine])) {
                continue;
            }
            $s = $summary[$engine];
            $label = self::ENGINE_LABEL[$engine];

            $md .= "### {$label}\n\n";
            $delta = $this->positionDelta($s['avg_position'], $prevAvg[$engine] ?? null);
            $md .= "- **Sichtbare Keywords:** {$s['count']}\n";
            $md .= "- **Ø-Position:** " . $this->fmtPos($s['avg_position']) . $delta . "\n";
            $md .= "- **Impressionen:** " . number_format($s['impressions'], 0, ',', '\'') . "\n";
            $md .= "- **Klicks:** " . number_format($s['clicks'], 0, ',', '\'') . "\n\n";

            // Top-Keywords nach Impressionen.
            $top = array_slice($s['rows'], 0, 10);
            if ($top) {
                $md .= "| Keyword | Position | Impressionen | Klicks |\n|---|---:|---:|---:|\n";
                foreach ($top as $r) {
                    $md .= '| ' . $this->cell((string) ($r['keyword'] ?? '—'))
                        . ' | ' . $this->fmtPos($r['position'] !== null ? (float) $r['position'] : null)
                        . ' | ' . (int) ($r['impressions'] ?? 0)
                        . ' | ' . (int) ($r['clicks'] ?? 0) . " |\n";
                }
                $md .= "\n";
            }
        }

        return $md;
    }

    /** Noch nicht erhobene Bereiche transparent ausweisen. */
    private function pendingSections(): string
    {
        $md  = "## 2. Onsite / Technisches SEO\n\n";
        $md .= "> _Noch nicht erhoben._ Geplant: technische Checks (Meta, Headings, hreflang, "
            . "Broken Links, Core Web Vitals) für die wichtigsten Seiten via DataForSEO OnPage "
            . "+ PageSpeed/CrUX.\n\n";

        $md .= "## 3. Offsite / Backlinks\n\n";
        $md .= "> _Noch nicht erhoben._ Geplant: referring domains, neue/verlorene Links, "
            . "Autoritäts-Trend, Spam-Score via DataForSEO Backlinks.\n\n";

        $md .= "## 4. GEO — Sichtbarkeit in KI-Antworten\n\n";
        $md .= "> _Noch nicht erhoben._ Geplant: Erwähnungen/Citations in ChatGPT, Perplexity, "
            . "Gemini, Google AI Overview + Bing-AI (Copilot). Die GEO-Prompts sind im Onboarding "
            . "bereits definiert.\n\n";

        return $md;
    }

    private function positionDelta(?float $now, ?float $prev): string
    {
        if ($now === null || $prev === null) {
            return '';
        }
        $diff = round($prev - $now, 1); // kleinere Position = besser → positiver diff = Verbesserung
        if (abs($diff) < 0.05) {
            return ' (±0)';
        }
        return $diff > 0
            ? " (▲ {$diff} verbessert)"
            : ' (▼ ' . abs($diff) . ' verschlechtert)';
    }

    private function fmtPos(?float $pos): string
    {
        return $pos === null ? '—' : number_format($pos, 1, ',', '');
    }

    private function monthLabel(string $period): string
    {
        $months = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
            'August', 'September', 'Oktober', 'November', 'Dezember'];
        [$y, $m] = explode('-', $period);
        return ($months[(int) $m] ?? $m) . ' ' . $y;
    }

    private function monthShort(string $period): string
    {
        $months = [1 => 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul',
            'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
        [$y, $m] = explode('-', $period);
        return ($months[(int) $m] ?? $m) . ' ' . substr($y, 2);
    }

    private function cell(string $v): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $v);
    }
}
