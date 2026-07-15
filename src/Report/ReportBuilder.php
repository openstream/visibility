<?php

declare(strict_types=1);

namespace Openstream\Visibility\Report;

use Openstream\Visibility\App;
use Openstream\Visibility\Database\ClientRepository;
use Openstream\Visibility\Provider\ClaudeClient;
use Symfony\Component\Yaml\Yaml;

/**
 * Erzeugt den ausführlichen Visibility-Report (`.md`, Deutsch) aus den erhobenen
 * Daten: Intro + Executive Summary (LLM) + Markt-Kontext + Sichtbarkeits-Verlauf +
 * Rankings (Google/Bing) + GEO (KI-Sichtbarkeit). Onsite/Offsite folgen.
 */
final class ReportBuilder
{
    private const ENGINE_LABEL = ['google' => 'Google', 'bing' => 'Bing'];

    /** @param ?ClaudeClient $claude für die Executive Summary (optional — ohne: Report ohne Summary) */
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly ?ClaudeClient $claude = null,
    ) {}

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

        // Aktive GEO-Kanäle aus der Config (für die Grau-Darstellung im Markt-Kontext).
        $activeGeo = array_keys(array_filter($cfg['geo']['channels'] ?? ['chatgpt' => true, 'perplexity' => true]));

        // Gesamt-Traffic der Google-Property live aus GSC (die echte, aussagekräftige Zahl).
        $gscTotals = $this->gscTotals($cfg);

        // Detail-Abschnitte zuerst bauen (die Summary fasst deren Zahlen zusammen).
        $sections  = $this->marketContext($activeGeo);
        $sections .= $this->visibilityTrend($clientId, $period);
        $sections .= $this->searchRankings($clientId, $period, $prevPeriod, $gscTotals);
        $sections .= $this->onsiteOffsite($clientId, $period);
        $sections .= $this->geoSection($clientId, $period);

        $md  = "# Visibility-Report: {$name}\n\n";
        $md .= "**Domain:** {$domain}  \n";
        $md .= "**Berichtsmonat:** " . $this->monthLabel($period) . "  \n";
        $md .= "**Erstellt:** " . date('d.m.Y') . "\n\n";
        $md .= "---\n\n";

        $md .= $this->intro($name);
        $md .= $this->executiveSummary($clientId, $period, $name, $domain, $gscTotals);
        $md .= $sections;

        $md .= "\n---\n";
        $md .= "_Automatisch erstellt vom Visibility Dashboard. Datenquellen: Google Search "
            . "Console, Bing Webmaster Tools, DataForSEO._\n";

        return $md;
    }

    /**
     * Gesamt-Traffic der Google-Property live aus GSC. @return ?array{clicks:int,impressions:int,ctr:float,position:float}
     * @param array<string,mixed> $cfg
     */
    private function gscTotals(array $cfg): ?array
    {
        $siteUrl = $cfg['gsc']['site_url'] ?? null;
        if (!$siteUrl) {
            return null;
        }
        try {
            $gsc = \Openstream\Visibility\Provider\GscClient::fromEnv();
            $end = date('Y-m-d', strtotime('-3 days'));
            $start = date('Y-m-d', strtotime('-28 days'));
            return $gsc->totals($siteUrl, $start, $end);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Kurze „Was ist das?"-Einordnung für den Kunden. */
    private function intro(string $name): string
    {
        $md  = "## Worum es geht\n\n";
        $md .= "Dieser Report zeigt monatlich, wie sichtbar {$name} online ist, in "
            . "beiden Welten der Websuche:\n\n";
        $md .= "- **Klassische Suche (SEO):** Auf welchen Positionen erscheint die Website bei "
            . "Google und Bing? Wie entwickelt sich die Sichtbarkeit über die Zeit, wie steht es "
            . "um das technische Fundament (Onsite) und die Verlinkung von aussen (Offsite)?\n";
        $md .= "- **KI-Suche (GEO):** Wird die Marke in den Antworten von KI-Assistenten wie "
            . "ChatGPT, Perplexity, Google AI Overviews oder Microsoft Copilot erwähnt und zitiert? "
            . "Immer mehr Menschen suchen so, und dort sichtbar zu sein wird zunehmend entscheidend.\n\n";
        $md .= "Ziel: auf einen Blick sehen, wo {$name} gut sichtbar ist und wo Potenzial liegt.\n\n";
        return $md;
    }

    /**
     * Executive Summary am Anfang — per LLM aus den Kern-Kennzahlen formuliert (Deutsch,
     * mit Einordnung). Zum Kopieren als Mail-Text. Ohne Claude/bei Fehler: entfällt.
     */
    private function executiveSummary(int $clientId, string $period, string $name, string $domain, ?array $gscTotals): string
    {
        if ($this->claude === null) {
            return '';
        }
        $facts = $this->summaryFacts($clientId, $period, $gscTotals);
        if (!$facts) {
            return '';
        }

        $system = 'Du schreibst die Executive Summary eines monatlichen Sichtbarkeits-Reports für '
            . 'einen Schweizer Kunden. Deutsch, professionell, aber verständlich (kein Fachjargon '
            . 'ohne Erklärung). 4-6 kurze Bullet-Punkte. Fasse das Wichtigste zusammen: '
            . 'Google/Bing-Sichtbarkeit inkl. Trend, KI-Sichtbarkeit, und nenne EINE konkrete '
            . 'Chance/Empfehlung. '
            . 'WICHTIG zum Sichtbarkeits-Trend: Wenn die klassische Google-Sichtbarkeit sinkt, während '
            . 'die KI-Sichtbarkeit gut ist, ORDNE das ein: Ein Teil des Rückgangs kann daran liegen, '
            . 'dass sich die Nutzung von der klassischen Suche hin zu KI-Assistenten und Google '
            . 'AI Overviews verschiebt (Klicks wandern von den blauen Links in die KI-Antworten). '
            . 'Formuliere das als mögliche Erklärung, nicht als Gewissheit, und leite daraus ab, dass '
            . 'GEO-Sichtbarkeit wichtiger wird. '
            . 'Nutze für Klicks/Impressionen die GESAMTZAHLEN der Website (gsc_gesamt), NICHT die '
            . 'kleineren Zahlen einzelner getrackter Keywords. '
            . 'Keine erfundenen Zahlen, nur die gelieferten Fakten. Beginne direkt mit der Aussage, '
            . 'keine Anrede. '
            . 'DEUTSCHE SCHREIBREGELN (streng befolgen): kein Gedankenstrich/Longdash (— oder –); '
            . 'Schweizer Schreibweise mit ss statt ß (dass, aussen, grösser); Umlaute normal (ä/ö/ü); '
            . 'KEIN Komma vor „und" ausser wenn ein Nebensatz davor endet; Fettschrift nur für den '
            . 'Kernbefund am Anfang eines Bullet-Punkts, nicht mitten im Satz.';
        $prompt = "Kunde: {$name} ({$domain}), Monat: " . $this->monthLabel($period) . "\n\n"
            . "Fakten:\n" . json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        try {
            $text = $this->claude->text($prompt, $system, 800);
        } catch (\Throwable $e) {
            return ''; // Report bleibt vollständig, nur ohne Summary
        }

        $md  = "## Zusammenfassung\n\n";
        $md .= $this->gray('Kurzfassung zum Weiterleiten (z. B. per Mail). Details in den Abschnitten darunter.') . "\n\n";
        $md .= trim($text) . "\n\n---\n\n";
        return $md;
    }

    /**
     * Kern-Kennzahlen für die Summary. @return array<string,mixed>
     * @param ?array{clicks:int,impressions:int,ctr:float,position:float} $gscTotals
     */
    private function summaryFacts(int $clientId, string $period, ?array $gscTotals): array
    {
        $facts = [];

        // Gesamt-Traffic der Website (die massgebliche Zahl für Klicks/Impressionen).
        if ($gscTotals) {
            $facts['gsc_gesamt_letzte_28_tage'] = [
                'klicks' => $gscTotals['clicks'],
                'impressionen' => $gscTotals['impressions'],
                'klickrate_prozent' => $gscTotals['ctr'],
                'durchschnittsposition' => $gscTotals['position'],
                'hinweis' => 'gesamter organischer Google-Traffic der Website',
            ];
        }

        // Sichtbarkeits-Trend
        $hist = $this->repo->visibilityHistory($clientId, 'google', $period);
        if (count($hist) >= 2) {
            $first = (float) $hist[0]['etv'];
            $last = (float) end($hist)['etv'];
            $facts['google_sichtbarkeit_trend'] = [
                'von_etv' => round($first), 'auf_etv' => round($last),
                'veränderung_prozent' => $first > 0 ? round(($last - $first) / $first * 100) : null,
                'zeitraum_monate' => count($hist),
            ];
        }

        // Getrackte Keywords (Subset — NICHT der Gesamt-Traffic).
        $rank = $this->repo->rankingSummary($clientId, $period);
        foreach (['google', 'bing'] as $e) {
            if (isset($rank[$e])) {
                $facts["getrackte_keywords_{$e}"] = [
                    'anzahl_sichtbar' => $rank[$e]['count'],
                    'durchschnittsposition' => $rank[$e]['avg_position'],
                    'hinweis' => 'nur die getrackten Einzel-Keywords, nicht der Gesamt-Traffic',
                ];
            }
        }

        // GEO
        $geo = $this->repo->geoSummary($clientId, $period);
        foreach ($geo as $engine => $s) {
            $facts['ki_sichtbarkeit'][$engine] = [
                'prompts' => $s['prompts'],
                'erwähnt' => $s['mentioned'],
                'erwähnungsrate_prozent' => $s['prompts'] > 0 ? round($s['mentioned'] / $s['prompts'] * 100) : 0,
            ];
        }

        return $facts;
    }

    /** Markt-Kontext CH aus config/market/switzerland.yaml (Donut-Kandidat). */
    /**
     * @param array<int,string> $activeGeo aktive GEO-Kanäle (engine-slugs), Rest wird grau dargestellt
     */
    private function marketContext(array $activeGeo): string
    {
        $file = App::get()->configPath('market/switzerland.yaml');
        if (!is_file($file)) {
            return '';
        }
        $m = Yaml::parseFile($file);
        $md = "## Markt-Kontext Schweiz\n\n";
        $md .= "_Warum welche Kanäle zählen, Marktanteile (Quelle: {$m['source']}, Stand "
            . "{$m['as_of']})._\n\n";

        // Suchmaschinen: getrackte (Google + Bing) mit % und Summe; übrige grau ohne %.
        $trackedNames = ['Google', 'Bing'];
        $tracked = [];
        $untracked = [];
        foreach ($m['search_engines'] ?? [] as $s) {
            if (in_array($s['name'], $trackedNames, true)) {
                $tracked[] = $s;
            } else {
                $untracked[] = $s['name'];
            }
        }
        $trackedSum = array_sum(array_map(static fn($s) => (float) $s['share'], $tracked));
        $md .= "**Suchmaschinen (getrackt):** ";
        $md .= implode(' · ', array_map(fn($s) => "{$s['name']} {$s['share']} %", $tracked));
        $md .= '  ·  zusammen ' . number_format($trackedSum, 1, ',', '') . ' %';
        if ($untracked) {
            $md .= "  \n" . $this->gray('nicht getrackt: ' . implode(', ', $untracked));
        }
        $md .= "\n\n";

        // KI-Assistenten: aktive (getrackte GEO-Kanäle) normal mit %, inaktive grau mit %.
        $md .= "**KI-Assistenten:**  \n";
        $active = [];
        $inactive = [];
        foreach ($m['ai_assistants'] ?? [] as $a) {
            $slug = $this->assistantSlug($a['name']);
            $entry = "{$a['name']} {$a['share']} %";
            if (in_array($slug, $activeGeo, true)) {
                $active[] = $entry;
            } else {
                $inactive[] = $entry;
            }
        }
        if ($active) {
            $md .= 'getrackt: ' . implode(' · ', $active) . "  \n";
        }
        if ($inactive) {
            $md .= $this->gray('nicht getrackt: ' . implode(' · ', $inactive));
        }
        $md .= "\n\n";

        return $md;
    }

    /** Name → engine-slug für den Abgleich mit aktiven GEO-Kanälen. */
    private function assistantSlug(string $name): string
    {
        return match (true) {
            str_contains($name, 'ChatGPT')    => 'chatgpt',
            str_contains($name, 'Gemini')     => 'gemini',
            str_contains($name, 'Claude')     => 'claude',
            str_contains($name, 'Perplexity') => 'perplexity',
            str_contains($name, 'Copilot')    => 'bing_ai',
            default                            => strtolower($name),
        };
    }

    /** Grauer Text (HTML — wird in gerenderten Viewern/HTML-Report grau, in reinem MD ignoriert). */
    private function gray(string $text): string
    {
        return "<span style=\"color:#888\">{$text}</span>";
    }

    /** Sichtbarkeits-Verlauf (Google, historisch) — Sparkline + Tabelle + Trend. */
    private function visibilityTrend(int $clientId, string $period): string
    {
        $hist = $this->repo->visibilityHistory($clientId, 'google', $period);
        if (count($hist) < 2) {
            return ''; // ohne Verlauf (min. 2 Punkte) kein Trend-Abschnitt
        }

        $md = "## Sichtbarkeits-Verlauf (Google)\n\n";
        $md .= "_Geschätzte Sichtbarkeit (ETV) und Anzahl rankender Keywords je Monat, "
            . "rückwirkend aus DataForSEO. Zeigt den Trend, nicht nur die Momentaufnahme._\n\n";
        $md .= $this->gray('**ETV** (Estimated Traffic Value) = geschätzter monatlicher '
            . 'organischer Traffic der Domain. Berechnet aus allen rankenden Keywords: '
            . 'Suchvolumen × erwartete Klickrate für die jeweilige Position, aufsummiert. '
            . 'Ein höherer Wert = mehr Sichtbarkeit. Ideal als einzelne Trend-Kennzahl.') . "\n\n";

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
    /**
     * @param ?array{clicks:int,impressions:int,ctr:float,position:float} $gscTotals
     *        Gesamt-Traffic der Google-Property (live aus GSC) — die aussagekräftige Zahl.
     */
    private function searchRankings(int $clientId, string $period, string $prevPeriod, ?array $gscTotals): string
    {
        $summary = $this->repo->rankingSummary($clientId, $period);

        $md = "## 1. Suchmaschinen-Rankings\n\n";

        // (a) Gesamt-Traffic der Website bei Google (echte Property-Zahlen, kein getracktes Subset).
        if ($gscTotals) {
            $md .= "### Google gesamt (ganze Website)\n\n";
            $md .= "_Der gesamte organische Google-Traffic der Website in den letzten 28 Tagen "
                . "(Quelle: Google Search Console)._\n\n";
            $md .= '- Klicks: ' . number_format($gscTotals['clicks'], 0, ',', '\'') . "\n";
            $md .= '- Impressionen: ' . number_format($gscTotals['impressions'], 0, ',', '\'') . "\n";
            $md .= '- Ø-Position: ' . $this->fmtPos($gscTotals['position'])
                . ' · Klickrate: ' . number_format($gscTotals['ctr'], 1, ',', '') . " %\n\n";
        }

        if (!$summary) {
            $md .= $this->gray('Für die getrackten Keywords liegen für ' . $this->monthLabel($period)
                . ' noch keine Positionsdaten vor.') . "\n\n";
            return $md;
        }

        // (b) Getrackte Keywords — ausdrücklich als Subset gekennzeichnet, damit die
        //     kleinen Zahlen nicht mit dem Gesamt-Traffic verwechselt werden.
        $md .= "### Getrackte Keywords\n\n";
        $md .= $this->gray('Dies ist eine Auswahl der für Sie definierten Keywords, nicht der '
            . 'gesamte Website-Traffic (siehe oben). Klicks/Impressionen beziehen sich nur auf diese '
            . 'einzelnen Begriffe.') . "\n\n";

        foreach (['google', 'bing'] as $engine) {
            if (!isset($summary[$engine])) {
                continue;
            }
            $s = $summary[$engine];
            $label = self::ENGINE_LABEL[$engine];

            $md .= "**{$label}:** {$s['count']} getrackte Keywords sichtbar, "
                . 'Ø-Position ' . $this->fmtPos($s['avg_position']) . "\n\n";

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

    /** Onsite (technisches SEO) + Offsite (Backlinks) aus den erhobenen Daten. */
    private function onsiteOffsite(int $clientId, string $period): string
    {
        $md = $this->onsiteSection($clientId, $period);
        $md .= $this->offsiteSection($clientId, $period);
        return $md;
    }

    private function onsiteSection(int $clientId, string $period): string
    {
        $audit = $this->repo->onsiteAudit($clientId, $period);
        $md = "## 2. Onsite / Technisches SEO\n\n";

        if (!$audit) {
            $md .= $this->gray('Noch nicht erhoben. Erhebung mit `collect --onsite`.') . "\n\n";
            return $md;
        }

        // Problem-Häufigkeit über alle Seiten aggregieren.
        $problemCounts = [];
        $pagesWithProblems = 0;
        foreach ($audit as $p) {
            if (!empty($p['problems'])) {
                $pagesWithProblems++;
            }
            foreach ($p['problems'] ?? [] as $prob) {
                $problemCounts[$prob] = ($problemCounts[$prob] ?? 0) + 1;
            }
        }
        arsort($problemCounts);

        $md .= "_Technische Prüfung der " . count($audit) . " wichtigsten Seiten "
            . "(Quelle: DataForSEO OnPage)._\n\n";
        $md .= '- Geprüfte Seiten: ' . count($audit) . "\n";
        $md .= '- Seiten mit Auffälligkeiten: ' . $pagesWithProblems . "\n\n";

        if ($problemCounts) {
            $md .= "**Häufigste technische Auffälligkeiten:**\n\n";
            $md .= "| Auffälligkeit | Betroffene Seiten |\n|---|---:|\n";
            foreach (array_slice($problemCounts, 0, 8, true) as $prob => $cnt) {
                $md .= "| {$prob} | {$cnt} |\n";
            }
            $md .= "\n";
        } else {
            $md .= "Keine technischen Auffälligkeiten auf den geprüften Seiten. Sehr gut.\n\n";
        }

        return $md;
    }

    private function offsiteSection(int $clientId, string $period): string
    {
        $b = $this->repo->backlinks($clientId, $period);
        $md = "## 3. Offsite / Backlinks\n\n";

        if (!$b) {
            $md .= $this->gray('Noch nicht erhoben. Erhebung mit `collect --offsite`.') . "\n\n";
            return $md;
        }

        $md .= "_Verlinkung der Website von aussen, ein Signal für Autorität und Vertrauen "
            . "(Quelle: DataForSEO Backlinks)._\n\n";
        $md .= '- **Domain Rank:** ' . ((int) $b['domain_rank']) . " / 1000  \n  ";
        $md .= $this->gray('Autoritäts-Kennzahl von DataForSEO (0 bis 1000). Es gibt keinen '
            . 'offiziellen Google-Wert; auch Moz "Domain Authority" oder Ahrefs "Domain Rating" '
            . 'sind Schätzungen von Drittanbietern.') . "\n\n";
        $md .= '- Backlinks gesamt: ' . number_format((int) $b['backlinks_total'], 0, ',', '\'') . "\n";
        $md .= '- Verweisende Domains: ' . number_format((int) $b['referring_domains'], 0, ',', '\'') . "\n";
        if ($b['new_last_period'] !== null || $b['lost_last_period'] !== null) {
            $md .= '- Neue / verlorene Links (Zeitraum): +' . (int) $b['new_last_period']
                . ' / -' . (int) $b['lost_last_period'] . "\n";
        }
        $md .= "\n";

        return $md;
    }

    /** GEO — Sichtbarkeit in KI-Antworten (ChatGPT/Gemini/Claude + Bing-AI/Copilot). */
    private function geoSection(int $clientId, string $period): string
    {
        $summary = $this->repo->geoSummary($clientId, $period);
        $md = "## 4. GEO: Sichtbarkeit in KI-Antworten\n\n";

        if (!$summary) {
            $md .= "> _Noch nicht erhoben._ Geplant: Erwähnungen/Citations in ChatGPT, Gemini, "
                . "Claude, Perplexity + Bing-AI (Copilot). Erhebung mit `collect --geo`.\n\n";
            return $md;
        }

        $md .= "_Wird die Marke in KI-Antworten auf die definierten Prompts erwähnt/zitiert? "
            . "Pro Kanal: Anteil der Prompts mit Erwähnung._\n\n";
        $md .= "| KI-Kanal | Prompts | Erwähnt | Zitiert | Sichtbarkeit |\n|---|---:|---:|---:|---:|\n";

        $labels = ['chatgpt' => 'ChatGPT', 'gemini' => 'Gemini', 'claude' => 'Claude',
            'perplexity' => 'Perplexity', 'bing_ai' => 'Copilot / Bing-AI', 'ai_overview' => 'Google AI Overview / AI Mode'];
        foreach ($labels as $engine => $label) {
            if (!isset($summary[$engine])) {
                continue;
            }
            $s = $summary[$engine];
            $rate = $s['prompts'] > 0 ? round($s['mentioned'] / $s['prompts'] * 100) : 0;
            $md .= "| {$label} | {$s['prompts']} | {$s['mentioned']} | {$s['cited']} | {$rate} % |\n";
        }
        $md .= "\n";

        // Noch nicht erhobene GEO-Kanäle transparent nennen (Herkunft der Daten).
        $md .= $this->gray('Noch nicht im Report: **Copilot / Bing-AI** (Datenquelle: Bing '
            . 'AI-Performance-CSV) und **Google AI Overview / AI Mode** (Datenquelle: GSC '
            . 'Search-Generative-AI-Report, bei CH-Domains noch nicht ausgerollt). Beide zählen '
            . 'zu GEO, nicht zu klassischem SEO.') . "\n\n";

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
