<?php

declare(strict_types=1);

namespace Openstream\Visibility\Report;

use Openstream\Visibility\App;
use Openstream\Visibility\Chart\ReportCharts;
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

    /**
     * @param ?ClaudeClient $claude für die Executive Summary (optional — ohne: Report ohne Summary)
     * @param ?ReportCharts $charts erzeugt SVG-Diagramme neben dem Report (optional — ohne: Text-Report)
     */
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly ?ClaudeClient $claude = null,
        private readonly ?ReportCharts $charts = null,
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

        // Gesamt-Traffic der Google-Property aus GSC für den Berichtsmonat (echte Zahl).
        $gscTotals = $this->gscTotals($cfg, $period);
        // Echte GSC-Positions-Verteilung (alle Queries) — zeigt die realen #1/Top-Rankings.
        $gscDist = $this->gscDistribution($cfg, $period);

        // OVS zuerst berechnen + speichern (Dach-Zahl; die Summary/der Abschnitt nutzen ihn).
        $ovs = $this->computeAndStoreOvs($clientId, $period, $gscTotals);

        // Detail-Abschnitte zuerst bauen (die Summary fasst deren Zahlen zusammen).
        $sections  = $this->ovsSection($clientId, $period, $ovs);
        $sections .= $this->marketContext($activeGeo);
        $sections .= $this->visibilityTrend($clientId, $period);
        $sections .= $this->searchRankings($clientId, $period, $prevPeriod, $gscTotals, $gscDist);
        $sections .= $this->visibilityBreadth($clientId, $period);
        $sections .= $this->onsiteOffsite($clientId, $period, $cfg);
        $sections .= $this->geoSection($clientId, $period, $name);
        $sections .= $this->socialSection($clientId, $period);
        $sections .= $this->newsletterSection($clientId, $period, $cfg);

        $md  = "# " . $this->reportTitle($name, $cfg) . "\n\n";
        $md .= "**Website:** " . $this->websiteLink($domain) . "  \n";
        if ($handles = $this->socialHandles($cfg)) {
            $md .= "**Social Media:** {$handles}  \n";
        }
        $md .= "**Berichtsmonat:** " . $this->monthLabel($period) . "  \n";
        $md .= "**Erstellt:** " . date('d.m.Y') . "\n\n";
        $md .= "---\n\n";

        $hasSocial = $this->repo->socialMonthly($clientId, $period) !== [];
        $md .= $this->intro($name, $hasSocial, $cfg);
        $md .= $this->executiveSummary($clientId, $period, $name, $domain, $gscTotals);
        $md .= $sections;

        $md .= "\n---\n";
        $md .= "_Automatisch erstellt vom Visibility Dashboard. Datenquellen: Google Search "
            . "Console, Bing Webmaster Tools, Bing AI Performance, DataForSEO (SERP, OnPage, "
            . "Backlinks, AI Optimization), YouTube Data & Analytics API, Instagram Graph API, "
            . "TikTok Display API, Sendy._  \n";
        $md .= "_Quellcode: [github.com/openstream/visibility](https://github.com/openstream/visibility)._\n";

        return $md;
    }

    /**
     * Gesamt-Traffic der Google-Property live aus GSC. @return ?array{clicks:int,impressions:int,ctr:float,position:float}
     * @param array<string,mixed> $cfg
     */
    private function gscTotals(array $cfg, string $period): ?array
    {
        $siteUrl = $cfg['gsc']['site_url'] ?? null;
        if (!$siteUrl) {
            return null;
        }
        try {
            $gsc = \Openstream\Visibility\Provider\GscClient::fromEnv();
            // Genau den Berichtsmonat auswerten (unabhängig vom Erstellungsdatum). Falls der
            // Monat noch läuft, endet der Zeitraum beim letzten GSC-verfügbaren Tag (heute -3).
            [$start, $monthEnd] = [$period . '-01', date('Y-m-t', strtotime($period . '-01'))];
            $gscLatest = date('Y-m-d', strtotime('-3 days'));
            $end = min($monthEnd, $gscLatest);
            if ($end < $start) {
                return null; // Monat liegt komplett in der Zukunft / vor GSC-Daten
            }
            return $gsc->totals($siteUrl, $start, $end);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * ECHTE GSC-Positions-Verteilung für den Berichtsmonat (alle Queries, nicht nur getrackte).
     * @param array<string,mixed> $cfg
     * @return array<string,int>|null
     */
    private function gscDistribution(array $cfg, string $period): ?array
    {
        $siteUrl = $cfg['gsc']['site_url'] ?? null;
        if (!$siteUrl) {
            return null;
        }
        try {
            $gsc = \Openstream\Visibility\Provider\GscClient::fromEnv();
            $start = $period . '-01';
            $end = min(date('Y-m-t', strtotime($start)), date('Y-m-d', strtotime('-3 days')));
            if ($end < $start) {
                return null;
            }
            // Schwelle 3 Impressionen: filtert reine Einzeltreffer (1-2 Impressionen), zeigt
            // aber Suchanfragen mit echtem Volumen. Guter Kompromiss (ehrlich, nicht aufgebläht).
            return $gsc->positionDistribution($siteUrl, $start, $end, 3);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Social-Media-Handles des Kunden für den Report-Kopf, aus der Config (`social:`).
     * Zeigt nur Plattformen mit hinterlegtem Handle. Gibt '' zurück, wenn keine da sind.
     * @param array<string,mixed> $cfg
     */
    /**
     * Report-Titel inkl. Region (falls in der Config gesetzt): „Visibility Report für X in Y".
     * @param array<string,mixed> $cfg
     */
    private function reportTitle(string $name, array $cfg): string
    {
        $region = trim((string) ($cfg['locale']['region'] ?? ''));
        $suffix = $region !== '' ? " in {$region}" : '';
        return "Visibility Report für {$name}{$suffix}";
    }

    /**
     * Kürzt eine URL für Tabellen auf den Pfad (verlinkt auf die volle URL).
     * „https://www.x.ch/foo/bar/" → „[/foo/bar/](https://www.x.ch/foo/bar/)"; „/" → „Startseite".
     */
    private function shortUrl(string $url): string
    {
        if ($url === '') {
            return '—';
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        if ($path === '/' || $path === '') {
            // Startseite: bei einer Subdomain (nicht www) den Host zeigen statt „Startseite".
            $host = preg_replace('#^www\.#', '', (string) (parse_url($url, PHP_URL_HOST) ?: ''));
            $label = ($host !== '' && substr_count($host, '.') > 1) ? $host : 'Startseite';
        } else {
            $label = $path;
        }
        return "[{$label}]({$url})";
    }

    /**
     * Ist die verweisende (Sub-)Domain ein eigenes Kundenprojekt? Matcht die Root-Domain
     * aus der Config gegen die volle Domain (z.B. own_projects=amnesty.ch trifft shop.amnesty.ch).
     * @param array<int,string> $ownProjects Root-Domains (lowercase)
     */
    private function isOwnProject(string $domain, array $ownProjects): bool
    {
        $d = strtolower(preg_replace('#^www\.#', '', $domain));
        foreach ($ownProjects as $root) {
            if ($d === $root || str_ends_with($d, '.' . $root)) {
                return true;
            }
        }
        return false;
    }

    /** Host einer zitierten Quelle (ohne Schema/www/Tracking), verlinkt auf die volle URL. */
    private function citationHost(string $url): string
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        $host = preg_replace('#^www\.#', '', $host);
        return "[{$host}]({$url})";
    }

    /** Website als Markdown-Link mit www-Präfix (ohne Schema in der Anzeige). */
    private function websiteLink(string $domain): string
    {
        $host = preg_replace('#^https?://#', '', rtrim($domain, '/'));
        $display = str_starts_with($host, 'www.') ? $host : 'www.' . $host;
        return "[{$display}](https://{$display})";
    }

    /**
     * Social-Media-Handles fürs Header, jeweils verlinkt auf das Profil.
     * @param array<string,mixed> $cfg
     */
    private function socialHandles(array $cfg): string
    {
        $labels = ['youtube' => 'YouTube', 'tiktok' => 'TikTok', 'instagram' => 'Instagram'];
        $parts = [];
        foreach ($labels as $key => $label) {
            $handles = array_values(array_filter((array) ($cfg['social'][$key] ?? [])));
            $links = array_map(fn($h) => $this->socialLink($key, (string) $h), $handles);
            if ($links) {
                $parts[] = $label . ': ' . implode(', ', $links);
            }
        }
        return implode(' · ', $parts);
    }

    /**
     * Markdown-Link zum Social-Profil aus einem Handle/einer URL. Ist der Wert bereits
     * eine URL, wird sie direkt genutzt; sonst je Plattform die Profil-URL gebaut.
     */
    private function socialLink(string $platform, string $handle): string
    {
        $display = $handle;
        if (preg_match('#^https?://#', $handle)) {
            $url = $handle;
        } else {
            $h = ltrim($handle, '@');
            $url = match ($platform) {
                'youtube'   => 'https://www.youtube.com/@' . $h,
                'tiktok'    => 'https://www.tiktok.com/@' . $h,
                'instagram' => 'https://www.instagram.com/' . $h,
                default     => '#',
            };
        }
        return "[{$display}]({$url})";
    }

    /**
     * Kurze „Was ist das?"-Einordnung für den Kunden.
     * @param array<string,mixed> $cfg
     */
    private function intro(string $name, bool $hasSocial, array $cfg = []): string
    {
        $hasNewsletter = !empty($cfg['newsletter']);
        $cadence = (int) ($cfg['newsletter']['cadence_months'] ?? 1);

        $md  = "## Worum es geht\n\n";
        $md .= "Dieser Report zeigt monatlich, wie sichtbar {$name} online ist "
            . ($hasSocial ? "- über Website, KI-Antworten und Social Media hinweg:\n\n" : "in "
            . "beiden Welten der Websuche:\n\n");
        $md .= "- **Klassische Suche (SEO):** Auf welchen Positionen erscheint die Website bei "
            . "Google und Bing? Wie entwickelt sich die Sichtbarkeit über die Zeit, wie steht es "
            . "um das technische Fundament (Onsite) und die Verlinkung von aussen (Offsite)?\n";
        $md .= "- **KI-Suche (GEO):** Wird die Marke in den Antworten von KI-Assistenten wie "
            . "ChatGPT, Perplexity, Google AI Overviews oder Microsoft Copilot erwähnt und zitiert? "
            . "Immer mehr Menschen suchen so, und dort sichtbar zu sein wird zunehmend entscheidend.\n";
        if ($hasSocial) {
            $md .= "- **Social Media:** Wie entwickeln sich die eigenen Kanäle (YouTube, TikTok, "
                . "Instagram) - Views und Follower über die Zeit?\n";
        }
        if ($hasNewsletter) {
            $rhythmus = $cadence >= 2
                ? " Er wird alle {$cadence} Monate verschickt; gezeigt werden jeweils die jüngsten Ausgaben."
                : '';
            $md .= "- **Newsletter:** Öffnungs- und Klickraten sowie das Wachstum der Abonnentenliste."
                . $rhythmus . "\n";
        }
        $md .= "\nZiel: auf einen Blick sehen, wo {$name} gut sichtbar ist und wo Potenzial liegt.\n\n";
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
            . 'Falls Social-Media-Daten geliefert werden (social_media/social_views_gesamt): '
            . 'erwähne die Social-Sichtbarkeit kurz (monatliche Views je Kanal bzw. gesamt) als '
            . 'eigenen Aspekt der Unternehmens-Sichtbarkeit. Wenn keine Social-Daten da sind, lass es weg. '
            . 'Falls ein Sichtbarkeits-Score (sichtbarkeits_score_ovs) geliefert wird: nenne ihn im '
            . 'ERSTEN Bullet als Gesamt-Kennzahl („aktive Sichtkontakte über alle Kanäle") inkl. Trend. '
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
            // 1500 Tokens: genug, dass die 4-6 Bullets sicher komplett durchpassen
            // (800 schnitt den letzten Punkt mitten im Satz ab). Die Kürze steuert der
            // System-Prompt, nicht das Limit.
            $text = $this->claude->text($prompt, $system, 1500);
        } catch (\Throwable $e) {
            return ''; // Report bleibt vollständig, nur ohne Summary
        }

        $md  = "## Zusammenfassung\n\n";
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

        // OVS — die Dach-Kennzahl (aktive Sichtkontakte, plattformübergreifend).
        $ovsHist = $this->repo->visibilityScoreHistory($clientId, $period);
        if ($ovsHist) {
            $last = (int) end($ovsHist)['score'];
            $facts['sichtbarkeits_score_ovs'] = ['aktueller_monat' => $last];
            if (count($ovsHist) >= 2) {
                $first = (int) $ovsHist[0]['score'];
                $facts['sichtbarkeits_score_ovs']['veränderung_prozent'] =
                    $first > 0 ? round(($last - $first) / $first * 100) : null;
            }
        }

        // Gesamt-Traffic der Website (die massgebliche Zahl für Klicks/Impressionen).
        if ($gscTotals) {
            $facts['gsc_gesamt_berichtsmonat'] = [
                'klicks' => $gscTotals['clicks'],
                'impressionen' => $gscTotals['impressions'],
                'klickrate_prozent' => $gscTotals['ctr'],
                'durchschnittsposition' => $gscTotals['position'],
                'hinweis' => 'gesamter organischer Google-Traffic der Website im Berichtsmonat',
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

        // Social Media (eigene Kanäle) — monatliche Views je Plattform + Total.
        $social = $this->repo->socialMonthly($clientId, $period);
        if ($social) {
            $totalViews = 0;
            foreach ($social as $r) {
                if ($r['monthly_views'] !== null) {
                    $facts['social_media'][$r['platform']] = [
                        'views_monat' => $r['monthly_views'],
                        'follower' => $r['followers'],
                    ];
                    $totalViews += $r['monthly_views'];
                }
            }
            if ($totalViews > 0) {
                $facts['social_views_gesamt'] = $totalViews;
            }
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

        // Donut-Diagramme der Marktanteile (Momentaufnahme).
        if ($this->charts !== null) {
            $md .= $this->charts->marketSearchEngines($file);
            $md .= $this->charts->marketAiAssistants($file);
        }

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
    /**
     * Sichtbarkeitsbreite (DataForSEO Labs): ALLE Keywords, für die die Domain rankt —
     * über die manuell getrackten hinaus. Deckt ungeahnte Rankings + Chancen auf und zeigt,
     * welche eigenen Seiten die Sichtbarkeit tragen. Blendet sich ohne Labs-Daten aus.
     */
    private function visibilityBreadth(int $clientId, string $period): string
    {
        $labs = $this->repo->labsSnapshot($clientId, $period);
        if (!$labs || !$labs['ranked_total']) {
            return '';
        }

        $md = "## Sichtbarkeitsbreite (Google, alle Rankings)\n\n";
        $md .= '_Alle Keywords, für die die Website in der Schweiz rankt, nicht nur die getrackten '
            . "(Quelle: DataForSEO Labs)._\n\n";
        $md .= '**Die Website rankt in der Schweiz für ' . number_format($labs['ranked_total'], 0, ',', '\'')
            . " Keywords.**\n\n";

        // Stärkste Rankings (Top nach geschätztem Traffic), inkl. solcher, die NICHT getrackt sind.
        $top = array_slice($labs['ranked_top'], 0, 12);
        if ($top) {
            $md .= "**Stärkste Rankings (nach geschätztem Traffic):**\n\n";
            $md .= "| Keyword | Position | Suchvol./Mt. |\n|---|---:|---:|\n";
            foreach ($top as $r) {
                $pos = ($r['position'] ?? null) !== null ? (int) $r['position'] : '—';
                $vol = ($r['volume'] ?? null) !== null
                    ? number_format((int) $r['volume'], 0, ',', '\'') : '—';
                $md .= '| ' . $this->cell((string) ($r['keyword'] ?? '—')) . " | {$pos} | {$vol} |\n";
            }
            $md .= "\n";
            $md .= $this->gray('Diese Rankings entstehen organisch, auch für nicht aktiv getrackte '
                . 'Begriffe. Keywords mit hohem Volumen auf mittlerer Position (11 bis 30) sind oft '
                . 'die grössten Chancen: schon sichtbar, aber mit Optimierungspotenzial auf Seite 1.') . "\n\n";
        }

        // Stärkste eigene Seiten (nach Rankings/Traffic).
        $pages = array_slice($labs['top_pages'], 0, 8);
        if ($pages) {
            $md .= "**Stärkste Seiten (nach Rankings und geschätztem Traffic):**\n\n";
            $md .= "| Seite | Keywords | Sichtbarkeit (ETV) |\n|---|---:|---:|\n";
            foreach ($pages as $p) {
                $etv = ($p['etv'] ?? null) !== null ? number_format((float) $p['etv'], 0, ',', '\'') : '—';
                $md .= '| ' . $this->shortUrl((string) ($p['page'] ?? '')) . ' | '
                    . (int) ($p['keywords'] ?? 0) . " | {$etv} |\n";
            }
            $md .= "\n";
            $md .= $this->gray('Zeigt, welche Inhalte die meiste organische Sichtbarkeit tragen — '
                . 'ein Signal, wo sich weiterer, ähnlicher Content lohnt.') . "\n\n";
        }

        return $md;
    }

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
        // Echtes Liniendiagramm, wenn Charts aktiv sind; sonst Text-Sparkline als Fallback.
        $chart = $this->charts?->visibilityTrend($hist) ?? '';
        if ($chart !== '') {
            $md .= $chart;
        } else {
            $md .= "**Sichtbarkeit:** " . $this->sparkline($etv) . "  \n";
        }
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
    private function searchRankings(int $clientId, string $period, string $prevPeriod, ?array $gscTotals, ?array $gscDist = null): string
    {
        $summary = $this->repo->rankingSummary($clientId, $period);

        $md = "## 1. Suchmaschinen-Rankings\n\n";

        // (a) Gesamt-Traffic der Website bei Google (echte Property-Zahlen, kein getracktes Subset).
        if ($gscTotals) {
            $md .= "### Google gesamt (ganze Website)\n\n";
            $md .= "_Der gesamte organische Google-Traffic der Website im " . $this->monthLabel($period)
                . " (Quelle: Google Search Console)._\n\n";
            $md .= '- Klicks: ' . number_format($gscTotals['clicks'], 0, ',', '\'') . "\n";
            $md .= '- Impressionen: ' . number_format($gscTotals['impressions'], 0, ',', '\'') . "\n";
            $md .= '- Ø-Position: ' . $this->fmtPos($gscTotals['position'])
                . ' · Klickrate: ' . number_format($gscTotals['ctr'], 1, ',', '') . " %\n\n";
        }

        // (a2) ECHTE Positions-Verteilung aus GSC — wie viele Suchanfragen tatsächlich auf
        //      #1/Top-3/… ranken (alle Queries der Website, nicht nur die getrackten Keywords).
        if ($gscDist && $gscDist['relevant'] > 0) {
            $md .= "### Google-Rankings (echt, aus GSC)\n\n";
            $md .= "_Auf welchen Positionen die Website im " . $this->monthLabel($period)
                . " tatsächlich rankt, über alle Suchanfragen (nicht nur die getrackten Keywords). "
                . "Nur Anfragen mit mindestens 3 Impressionen, um reine Einzeltreffer herauszufiltern._\n\n";
            $md .= "| Position | Anzahl Suchanfragen |\n|---|---:|\n";
            $md .= '| **Platz 1** | ' . number_format($gscDist['pos_1'], 0, ',', '\'') . " |\n";
            $md .= '| Platz 2–3 | ' . number_format($gscDist['pos_2_3'], 0, ',', '\'') . " |\n";
            $md .= '| Platz 4–10 | ' . number_format($gscDist['pos_4_10'], 0, ',', '\'') . " |\n";
            $md .= '| Platz 11–20 | ' . number_format($gscDist['pos_11_20'], 0, ',', '\'') . " |\n";
            $md .= '| Platz 21–50 | ' . number_format($gscDist['pos_21_50'], 0, ',', '\'') . " |\n";
            $top10 = $gscDist['pos_1'] + $gscDist['pos_2_3'] + $gscDist['pos_4_10'];
            $md .= "\n";
            $md .= "> **" . number_format($gscDist['pos_1'], 0, ',', '\'') . " Suchanfragen auf Platz 1**, "
                . number_format($top10, 0, ',', '\'') . " in den Top 10 (von "
                . number_format($gscDist['relevant'], 0, ',', '\'') . " relevanten Suchanfragen).\n\n";
            $md .= $this->gray('Diese Zahlen kommen direkt aus Google (Search Console) und zeigen '
                . 'die echten Platzierungen. Der Sichtbarkeits-Verlauf weiter oben misst dagegen eine '
                . 'Auswahl strategischer Keywords im gesamten Schweizer Google-Wettbewerb (DataForSEO) '
                . 'und fällt daher konservativer aus. Beide Sichten ergänzen sich.') . "\n\n";
        }

        // Verteilungs-Chart (Momentaufnahme) aus der jüngsten Historie-Zeile des Monats.
        if ($this->charts !== null) {
            $hist = $this->repo->visibilityHistory($clientId, 'google', $period);
            $latest = $hist ? end($hist) : null;
            $md .= $this->charts->rankingDistribution($latest ?: null);
        }

        if (!$summary) {
            $md .= $this->gray('Für die getrackten Keywords liegen für ' . $this->monthLabel($period)
                . ' keine Positionsdaten vor (in diesem Monat nicht erhoben). Die Gesamt- und '
                . 'GSC-Rankings oben sind davon unberührt.') . "\n\n";
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
                // Suchvolumen- + Difficulty-Spalten nur zeigen, wenn die Daten vorliegen
                // (refresh-volume bzw. collect --labs gelaufen).
                $hasVolume = array_filter($s['rows'], static fn($r) => ($r['search_volume'] ?? null) !== null) !== [];
                $hasDiff = array_filter($s['rows'], static fn($r) => ($r['difficulty'] ?? null) !== null) !== [];
                $head = '| Keyword |';
                $sep = '|---|';
                if ($hasVolume) { $head .= ' Suchvol./Mt. |'; $sep .= '---:|'; }
                if ($hasDiff)   { $head .= ' Wettbewerb |';   $sep .= '---:|'; }
                $head .= " Position | Impressionen | Klicks |\n";
                $sep .= "---:|---:|---:|\n";
                $md .= $head . $sep;
                foreach ($top as $r) {
                    $md .= '| ' . $this->cell((string) ($r['keyword'] ?? '—'));
                    if ($hasVolume) {
                        $md .= ' | ' . (($r['search_volume'] ?? null) !== null
                            ? number_format((int) $r['search_volume'], 0, ',', '\'') : '—');
                    }
                    if ($hasDiff) {
                        $md .= ' | ' . (($r['difficulty'] ?? null) !== null ? (int) $r['difficulty'] : '—');
                    }
                    $md .= ' | ' . $this->fmtPos($r['position'] !== null ? (float) $r['position'] : null)
                        . ' | ' . (int) ($r['impressions'] ?? 0)
                        . ' | ' . (int) ($r['clicks'] ?? 0) . " |\n";
                }
                $md .= "\n";
                if ($hasDiff) {
                    $md .= $this->gray('„Wettbewerb" ist die Ranking-Schwierigkeit (0 bis 100, '
                        . 'DataForSEO): je tiefer, desto leichter erreichbar. Ein Keyword mit hohem '
                        . 'Suchvolumen UND tiefem Wettbewerb ist die attraktivste Chance.') . "\n\n";
                }
            }
        }
        // Google da, Bing fehlt → erklären (Bing Webmaster Tools liefert getrackte Positionen
        // nur für den aktuellen Zeitraum, nicht rückwirkend für einen abgeschlossenen Monat).
        if (isset($summary['google']) && !isset($summary['bing'])) {
            $md .= $this->gray('Für Bing liegen in diesem Monat keine getrackten Keyword-Positionen '
                . 'vor: Die Bing Webmaster Tools liefern diese Daten nur für den aktuellen Zeitraum, '
                . 'nicht rückwirkend. Ab der laufenden Erhebung sind sie wieder enthalten.') . "\n\n";
        }

        return $md;
    }

    /** Onsite (technisches SEO) + Offsite (Backlinks) aus den erhobenen Daten. */
    /** @param array<string,mixed> $cfg */
    private function onsiteOffsite(int $clientId, string $period, array $cfg = []): string
    {
        $md = $this->onsiteSection($clientId, $period);
        $md .= $this->offsiteSection($clientId, $period, $cfg);
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

        // Problem-Häufigkeit + betroffene Beispielseiten je Problem aggregieren.
        $problemCounts = [];
        $problemPages = [];
        $pagesWithProblems = 0;
        foreach ($audit as $p) {
            if (!empty($p['problems'])) {
                $pagesWithProblems++;
            }
            foreach ($p['problems'] ?? [] as $prob) {
                $problemCounts[$prob] = ($problemCounts[$prob] ?? 0) + 1;
                $problemPages[$prob][] = (string) ($p['url'] ?? '');
            }
        }
        arsort($problemCounts);

        $md .= "_Technische Prüfung der " . count($audit) . " wichtigsten Seiten "
            . "(Quelle: DataForSEO OnPage)._\n\n";
        $md .= '- Geprüfte Seiten: ' . count($audit) . "\n";
        $md .= '- Seiten mit Auffälligkeiten: ' . $pagesWithProblems . "\n";

        // Durchschnittlicher OnPage-Score (0-100) über alle Seiten mit Wert.
        $scores = array_values(array_filter(array_map(
            static fn($p) => $p['onpage_score'] ?? null,
            $audit
        ), static fn($v) => $v !== null));
        if ($scores) {
            $avg = round(array_sum($scores) / count($scores), 1);
            $md .= '- Ø OnPage-Score: ' . number_format($avg, 1, ',', '') . " / 100  \n  ";
            $md .= $this->gray('Optimierungs-Kennzahl von DataForSEO je Seite (0 bis 100, höher ist '
                . 'besser): fasst technische Fehler und Warnungen zu einer Zahl zusammen.') . "\n";
        }

        // Performance: mittlere Ladezeiten (nur Seiten mit Timing-Werten).
        $tti = array_values(array_filter(array_map(static fn($p) => $p['tti_ms'] ?? null, $audit), static fn($v) => $v !== null));
        $ttfb = array_values(array_filter(array_map(static fn($p) => $p['ttfb_ms'] ?? null, $audit), static fn($v) => $v !== null));
        if ($tti || $ttfb) {
            $parts = [];
            if ($ttfb) {
                $parts[] = 'Serverantwort (TTFB) Ø ' . (int) round(array_sum($ttfb) / count($ttfb)) . ' ms';
            }
            if ($tti) {
                $parts[] = 'interaktiv nach Ø ' . (int) round(array_sum($tti) / count($tti)) . ' ms';
            }
            $md .= '- Ladezeiten: ' . implode(', ', $parts) . "\n";
            $slow = count(array_filter($tti, static fn($v) => $v > 3000));
            if ($slow > 0) {
                $md .= $this->gray("  ({$slow} Seite(n) langsamer als 3 Sekunden bis interaktiv)") . "\n";
            }
        }

        // Social-Sharing-Abdeckung (Open Graph / Twitter Card). Nur zeigen, wenn das Feld
        // in den Daten existiert (ältere Erhebungen ohne dieses Feld nicht als «0» ausweisen).
        $hasSocialData = array_filter($audit, static fn($p) => array_key_exists('has_og', $p)) !== [];
        if ($hasSocialData) {
            $ogCount = count(array_filter($audit, static fn($p) => !empty($p['has_og'])));
            $twCount = count(array_filter($audit, static fn($p) => !empty($p['has_twitter'])));
            $md .= '- Social-Sharing-Vorschau: ' . $ogCount . ' von ' . count($audit)
                . ' Seiten mit Open-Graph-Tags, ' . $twCount . " mit Twitter-Card  \n  ";
            $md .= $this->gray('Diese Tags bestimmen, wie eine Seite beim Teilen in Social Media und '
                . 'Chat-Apps als Vorschau (Bild, Titel, Text) erscheint.') . "\n";
        }
        $md .= "\n";

        if ($problemCounts) {
            $md .= "**Häufigste technische Auffälligkeiten (mit Beispielseiten):**\n\n";
            $md .= "| Auffälligkeit | Seiten | Beispiele |\n|---|---:|---|\n";
            foreach (array_slice($problemCounts, 0, 8, true) as $prob => $cnt) {
                $examples = array_slice(array_filter($problemPages[$prob] ?? []), 0, 2);
                $examples = array_map(fn($u) => $this->shortUrl($u), $examples);
                $md .= "| {$prob} | {$cnt} | " . implode(', ', $examples) . " |\n";
            }
            $md .= "\n";
        } else {
            $md .= "Keine technischen Auffälligkeiten auf den geprüften Seiten. Sehr gut.\n\n";
        }

        // Meta-Übersicht: konkrete Titel-/Description-Längen der Seiten mit Auffälligkeiten
        // bei Titel/Beschreibung (der Kunde sieht, WO genau nachgebessert werden sollte).
        $metaRows = [];
        foreach ($audit as $p) {
            $tl = (int) ($p['title_len'] ?? 0);
            $dl = (int) ($p['desc_len'] ?? 0);
            $flag = ($tl > 0 && ($tl < 30 || $tl > 60)) || ($dl > 0 && ($dl < 70 || $dl > 160)) || $dl === 0;
            if ($flag) {
                $metaRows[] = ['url' => (string) ($p['url'] ?? ''), 'title_len' => $tl, 'desc_len' => $dl];
            }
        }
        if ($metaRows) {
            $md .= "**Meta-Angaben zum Nachbessern (Auswahl):**\n\n";
            $md .= "| Seite | Titel-Länge | Beschreibung-Länge |\n|---|---:|---:|\n";
            foreach (array_slice($metaRows, 0, 6) as $r) {
                $tl = $r['title_len'] > 0 ? $r['title_len'] . ' Z.' : 'fehlt';
                $dl = $r['desc_len'] > 0 ? $r['desc_len'] . ' Z.' : 'fehlt';
                $md .= '| ' . $this->shortUrl($r['url']) . " | {$tl} | {$dl} |\n";
            }
            $md .= "\n";
            $md .= $this->gray('Empfehlung: Seitentitel 30–60 Zeichen, Meta-Beschreibung 70–160 '
                . 'Zeichen. Zu kurze, zu lange oder fehlende Angaben verschenken Klickpotenzial in '
                . 'den Suchergebnissen.') . "\n\n";
        }

        // Was technisch gut ist: Checks, die auf allen/fast allen Seiten erfüllt sind
        // (ausgewogenes Bild statt reiner Mängelliste).
        $goodCounts = [];
        foreach ($audit as $p) {
            foreach ($p['good'] ?? [] as $g) {
                $goodCounts[$g] = ($goodCounts[$g] ?? 0) + 1;
            }
        }
        $total = count($audit);
        $allGood = array_keys(array_filter($goodCounts, static fn($c) => $c === $total));
        if ($allGood) {
            $md .= "**Technisch gut gelöst (auf allen geprüften Seiten):**\n\n";
            foreach ($allGood as $g) {
                $md .= "- {$g}\n";
            }
            $md .= "\n";
        }

        return $md;
    }

    /** @param array<string,mixed> $cfg */
    private function offsiteSection(int $clientId, string $period, array $cfg = []): string
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

        // Stärkste verweisende Domains (nach Rang), mit echter (Sub-)Domain + dofollow.
        // Eigene Kundenprojekte werden markiert, damit klar wird, was fremde Autorität ist.
        $top = $b['top_referring_domains'] ?? [];
        if ($top) {
            $ownProjects = array_map('strtolower', (array) ($cfg['offsite']['own_projects'] ?? []));
            $hasOwn = false;

            $md .= "**Stärkste verweisende Domains (Beispiele):**\n\n";
            $md .= "| Domain | Rang | Link-Typ |\n|---|---:|---|\n";
            foreach (array_slice($top, 0, 10) as $d) {
                // www. ist technisch Subdomain, aber semantisch die Hauptdomain → für die
                // Anzeige entfernen; echte Subdomains (shop., blog., …) bleiben erhalten.
                $domain = preg_replace('#^www\.#', '', (string) $d['domain']);
                $rank = $d['rank'] !== null ? (string) $d['rank'] : '—';
                $type = ($d['dofollow'] ?? null) === null ? '—'
                    : ($d['dofollow'] ? 'dofollow' : 'nofollow');
                $isOwn = $this->isOwnProject($domain, $ownProjects);
                $marker = $isOwn ? ' ' . $this->gray('(Kundenprojekt)') : '';
                $hasOwn = $hasOwn || $isOwn;
                $md .= "| {$domain}{$marker} | {$rank} | {$type} |\n";
            }
            $md .= "\n";
            $note = 'Diese Domains verlinken auf die Website. Ein Link von einer Domain mit hohem '
                . 'Rang (0 bis 1000, DataForSEO) wiegt schwerer als viele Links von schwachen Seiten. '
                . '„dofollow" gibt Autorität weiter, „nofollow" nicht.';
            if ($hasOwn) {
                $note .= ' Als „(Kundenprojekt)" markierte Domains sind eigene Kundenseiten, die '
                    . 'zurückverlinken; die übrigen sind fremde Verweise.';
            }
            $md .= $this->gray($note) . "\n\n";
        }

        return $md;
    }

    /** GEO — Sichtbarkeit in KI-Antworten (ChatGPT/Gemini/Claude + Bing-AI/Copilot). */
    private function geoSection(int $clientId, string $period, string $name = ''): string
    {
        $summary = $this->repo->geoSummary($clientId, $period);
        $md = "## 4. GEO: Sichtbarkeit in KI-Antworten\n\n";

        if (!$summary) {
            $md .= "> _Noch nicht erhoben._ Geplant: Erwähnungen/Citations in ChatGPT, Gemini, "
                . "Claude, Perplexity + Bing-AI (Copilot). Erhebung mit `collect --geo`.\n\n";
            return $md;
        }

        $md .= "_Wird die Marke in KI-Antworten erwähnt oder als Quelle zitiert? "
            . "Pro Kanal: Anteil der Anfragen mit Erwähnung._\n\n";
        $md .= "| KI-Kanal | Anfragen | Erwähnt | Zitiert | Sichtbarkeit |\n|---|---:|---:|---:|---:|\n";

        // Prompt-basierte Kanäle: echte Erwähnungs-Rate (erwähnt/getestete Anfragen).
        // Bing-AI läuft NICHT über eigene Prompts, sondern über Microsofts Zitations-Export
        // (nur zitierte Queries) → keine Rate, separater Absatz weiter unten.
        $labels = ['chatgpt' => 'ChatGPT', 'gemini' => 'Gemini', 'claude' => 'Claude',
            'perplexity' => 'Perplexity', 'ai_overview' => 'Google AI Overview'];
        $rateSummary = [];
        foreach ($labels as $engine => $label) {
            if (!isset($summary[$engine])) {
                continue;
            }
            $s = $summary[$engine];
            $rateSummary[$engine] = $s;
            $rate = $s['prompts'] > 0 ? round($s['mentioned'] / $s['prompts'] * 100) : 0;
            $md .= "| {$label} | {$s['prompts']} | {$s['mentioned']} | {$s['cited']} | {$rate} % |\n";
        }
        $md .= "\n";

        // Balkendiagramm der Erwähnungsrate je Kanal (Momentaufnahme).
        if ($this->charts !== null) {
            $md .= $this->charts->geoVisibility($rateSummary);
        }

        $md .= $this->gray('Hinweis: Bei Google AI Overview zählt eine Zitierung als Quelle in der '
            . 'KI-Zusammenfassung (geprüft je Keyword). Bei den Chat-Assistenten zählt die Erwähnung '
            . 'der Marke in der Antwort auf die definierten Prompts.') . "\n\n";

        // Konkrete Beispiele je Kanal: getestete Anfrage + in KI-Antworten zitierte Quellen.
        $examples = $this->repo->geoExamples($clientId, $period);
        $exampleMd = '';
        foreach ($labels as $engine => $label) {
            $e = $examples[$engine] ?? null;
            if (!$e || ($e['example_prompt'] === null && !$e['citations'] && !$e['competitors'])) {
                continue;
            }
            $exampleMd .= "**{$label}**  \n";
            // Mehrere getestete Anfragen mit Ergebnis (erwähnt/zitiert), erwähnte zuerst.
            $results = $this->repo->geoPromptResults($clientId, $engine, $period, 6);
            foreach ($results as $r) {
                $status = $r['mentioned']
                    ? ($r['cited'] ? '✓ erwähnt und zitiert' : '✓ erwähnt')
                    : 'nicht erwähnt';
                $exampleMd .= '- „' . $r['prompt'] . '" — ' . $this->gray($status) . "\n";
            }
            if (!$results && $e['example_prompt'] !== null) {
                $status = $e['example_mentioned']
                    ? ($e['example_cited'] ? 'erwähnt und als Quelle zitiert' : 'erwähnt')
                    : 'nicht erwähnt';
                $exampleMd .= '- Beispiel-Anfrage: „' . $e['example_prompt'] . '" (' . $status . ")\n";
            }
            if ($e['citations']) {
                $cites = array_map(fn($u) => $this->citationHost($u), $e['citations']);
                $exampleMd .= '- In den Antworten zitierte Quellen: ' . implode(', ', $cites) . "\n";
            }
            if ($e['competitors']) {
                $exampleMd .= '- Genannte andere Anbieter: ' . implode(', ', $e['competitors']) . "\n";
            }
            $exampleMd .= "\n";
        }
        if ($exampleMd !== '') {
            $md .= "**Beispiele aus den KI-Antworten:**\n\n" . $exampleMd;
            $who = $name !== '' ? $name : 'der eigenen Marke';
            $md .= $this->gray('Die zitierten Quellen zeigen, welche Websites die KI-Assistenten '
                . 'aktuell als Antwort auf diese Fragen heranziehen. Wo dort andere Anbieter statt '
                . $who . ' stehen, liegt konkretes GEO-Potenzial.') . "\n\n";
        }

        // Copilot / Bing-AI (Microsoft): eigene Kennzahlen, KEINE Rate. Microsofts
        // AI-Performance-Export listet nur Anfragen, bei denen die Domain zitiert wurde
        // (eine Stichprobe), nicht die Grundgesamtheit aller Anfragen.
        $bingAi = $this->repo->bingAiSummary($clientId, $period);
        $bingStats = $this->repo->bingAiStats($clientId, $period);
        $md .= "### Copilot / Bing-AI (Microsoft)\n\n";
        if ($bingAi || $bingStats) {
            // Gesamt-Citations bevorzugt aus dem Overview-Report (echte Monatssumme),
            // sonst Fallback auf die Summe der Top-Queries.
            $totalCit = $bingStats['citations_total'] ?? ($bingAi['citations'] ?? 0);
            if ($totalCit) {
                $md .= '- Zitationen gesamt (Monat): ' . number_format($totalCit, 0, ',', '\'') . "\n";
            }
            if ($bingAi) {
                $md .= '- Grounding-Anfragen mit Zitierung: ' . number_format($bingAi['queries'], 0, ',', '\'') . "\n";
            }
            $md .= "\n";
            $md .= $this->gray('Quelle: Bing-AI-Performance-Report (Copilot & Bing-KI-Antworten). '
                . 'Microsoft liefert die Anfragen und Seiten, bei denen die Domain zitiert wurde, plus '
                . 'die Anzahl Zitationen. Eine Erwähnungs-Rate wie bei den Chat-Assistenten ist daher '
                . 'nicht möglich; die Werte sind laut Microsoft eine Stichprobe.') . "\n\n";

            // Meistzitierte eigene Seiten (welcher Content in KI-Antworten funktioniert).
            $pages = $bingStats['top_pages'] ?? [];
            if ($pages) {
                $md .= "**Meistzitierte Seiten in KI-Antworten:**\n\n";
                $md .= "| Seite | Zitationen |\n|---|---:|\n";
                foreach (array_slice($pages, 0, 10) as $p) {
                    $cit = number_format((int) ($p['citations'] ?? 0), 0, ',', '\'');
                    $md .= '| ' . $this->shortUrl((string) ($p['page'] ?? '')) . " | {$cit} |\n";
                }
                $md .= "\n";
                $md .= $this->gray('Diese Seiten werden von Copilot/Bing-AI am häufigsten als Quelle '
                    . 'herangezogen. Sie zeigen, welche Inhalte in KI-Antworten am besten funktionieren, '
                    . 'also wo sich weiterer, ähnlich aufbereiteter Content lohnt.') . "\n\n";
            }

            // Top-Grounding-Queries (welche Fragen zur Zitierung führten).
            $queries = $this->repo->bingAiQueries($clientId, $period, 10);
            if ($queries) {
                $md .= "**Top-Anfragen, bei denen zitiert wurde:**\n\n";
                $md .= "| Grounding-Anfrage | Zitationen |\n|---|---:|\n";
                foreach ($queries as $q) {
                    $cit = number_format((int) $q['citations'], 0, ',', '\'');
                    $md .= '| ' . $q['query'] . " | {$cit} |\n";
                }
                $md .= "\n";
                $md .= $this->gray('„Grounding-Anfragen" sind die Fragen, auf die Copilot/Bing-AI '
                    . 'geantwortet und dabei die Website zitiert hat.') . "\n\n";
            }
        } else {
            $md .= $this->gray('Noch nicht importiert. Export unter bing.com/webmasters (AI Performance, '
                . 'Beta, ohne API) als CSV, dann `import-bing-ai`.') . "\n\n";
        }

        return $md;
    }

    /**
     * Social Media — Views/Follower der eigenen Kanäle (YouTube/TikTok/Instagram).
     * Blendet sich aus, wenn keine Social-Daten erhoben wurden (Kunde ohne Kanal).
     */
    private function socialSection(int $clientId, string $period): string
    {
        $rows = $this->repo->socialMonthly($clientId, $period);
        if (!$rows) {
            return ''; // keine Kanäle / noch nicht erhoben → Abschnitt weglassen
        }

        $labels = ['youtube' => 'YouTube', 'tiktok' => 'TikTok', 'instagram' => 'Instagram'];
        $md = "## 5. Social Media\n\n";
        $md .= "_Sichtbarkeit der eigenen Social-Media-Kanäle: monatliche Views und "
            . "Follower je Plattform._\n\n";
        $md .= "| Plattform | Kanal | Views (Monat) | Follower |\n|---|---|---:|---:|\n";

        $totalViews = 0;
        $haveViews = false;
        foreach ($rows as $r) {
            $label = $labels[$r['platform']] ?? ucfirst($r['platform']);
            $mv = $r['monthly_views'];
            if ($mv !== null) {
                $totalViews += $mv;
                $haveViews = true;
            }
            $md .= '| ' . $label
                . ' | ' . $this->cell((string) $r['account'])
                . ' | ' . ($mv !== null ? number_format($mv, 0, ',', '\'') : '—')
                . ' | ' . ($r['followers'] !== null ? number_format($r['followers'], 0, ',', '\'') : '—')
                . " |\n";
        }
        $md .= "\n";

        if ($haveViews) {
            $md .= "**Views gesamt (alle Plattformen):** "
                . number_format($totalViews, 0, ',', '\'') . "\n\n";
        }

        $md .= $this->gray('Monatliche Views je Kanal. Bei per OAuth verbundenen Kanälen sind '
            . 'es die exakten Monatswerte der offiziellen Analytics-APIs; sonst der Zuwachs der '
            . 'Gesamt-Views im Monat. „—" bedeutet: noch kein Vergleichswert vorhanden.') . "\n\n";

        return $md;
    }

    /**
     * Newsletter — Owned Media (Öffnungs-/Klickrate, Listen-Wachstum je Ausgabe).
     * Blendet sich aus, wenn keine Newsletter-Daten erhoben wurden.
     */
    /** @param array<string,mixed> $cfg */
    private function newsletterSection(int $clientId, string $period, array $cfg = []): string
    {
        $rows = $this->repo->newsletterCampaigns($clientId, $period, 6);
        $hasNewsletter = !empty($cfg['newsletter']);
        $cadence = (int) ($cfg['newsletter']['cadence_months'] ?? 1);

        if (!$rows) {
            // Kunde ohne Newsletter → Abschnitt ganz weglassen. Kunde MIT Newsletter, aber
            // in diesem Monat keine Ausgabe (z.B. zweimonatlicher Versand) → Abschnitt zeigen
            // und erklären, warum keine Zahlen da sind (statt kommentarlos zu fehlen).
            if (!$hasNewsletter) {
                return '';
            }
            $md = "## 6. Newsletter\n\n";
            if ($cadence >= 2) {
                $md .= "_In diesem Monat wurde keine Newsletter-Ausgabe verschickt: Der Newsletter "
                    . "erscheint alle {$cadence} Monate. Kennzahlen (Öffnungs-/Klickraten, "
                    . "Listen-Wachstum) folgen wieder im nächsten Versandmonat._\n\n";
            } else {
                $md .= "_In diesem Monat wurde keine Newsletter-Ausgabe verschickt._\n\n";
            }
            return $md;
        }

        $md = "## 6. Newsletter\n\n";
        $rhythmusHinweis = $cadence >= 2
            ? " Der Newsletter erscheint alle {$cadence} Monate; gezeigt werden die jeweils "
              . "jüngsten Ausgaben, auch wenn im Berichtsmonat keine neue verschickt wurde."
            : '';
        $md .= "_Reichweite des eigenen Newsletters: Öffnungs- und Klickrate je Ausgabe, "
            . "plus Entwicklung der Listengrösse." . $rhythmusHinweis . "_\n\n";
        $md .= "| Ausgabe | Datum | Empfänger | Öffnungsrate | Klickrate | Abmeldungen |\n"
            . "|---|---|---:|---:|---:|---:|\n";

        $latestListSize = null;
        foreach ($rows as $r) {
            $rec = $r['recipients'] !== null ? (int) $r['recipients'] : null;
            $openRate = $rec ? round((int) ($r['opens'] ?? 0) / $rec * 100, 1) : null;
            $clickRate = $rec ? round((int) ($r['clicks'] ?? 0) / $rec * 100, 1) : null;
            if ($latestListSize === null && $r['list_size'] !== null) {
                $latestListSize = (int) $r['list_size'];
            }
            $subject = $r['subject'] ? $this->cell((string) $r['subject']) : '—';
            $md .= '| ' . $subject
                . ' | ' . ($r['sent_at'] ? $this->dateDe((string) $r['sent_at']) : '—')
                . ' | ' . ($rec !== null ? number_format($rec, 0, ',', '\'') : '—')
                . ' | ' . ($openRate !== null ? number_format($openRate, 1, ',', '') . ' %' : '—')
                . ' | ' . ($clickRate !== null ? number_format($clickRate, 1, ',', '') . ' %' : '—')
                . ' | ' . ($r['unsubscribes'] !== null ? (int) $r['unsubscribes'] : '—')
                . " |\n";
        }
        $md .= "\n";

        if ($latestListSize !== null) {
            $md .= "**Aktuelle Listengrösse:** " . number_format($latestListSize, 0, ',', '\'')
                . " Abonnenten\n\n";
        }

        $md .= $this->gray('Nur aggregierte Kennzahlen des eigenen Newsletters, keine '
            . 'Empfänger-Adressen. Öffnungsrate = eindeutige Öffnungen / Empfänger, '
            . 'Klickrate = eindeutige Klicks / Empfänger. „—" = vom Versandtool nicht '
            . 'geliefert.') . "\n\n";

        return $md;
    }

    private function dateDe(string $ymd): string
    {
        $t = strtotime($ymd);
        return $t ? date('d.m.Y', $t) : $ymd;
    }

    /**
     * Berechnet den OVS aus den Monatsdaten aller Kanäle und speichert ihn (Zeitreihe).
     * @param ?array{clicks:int,impressions:int,ctr:float,position:float} $gscTotals
     * @return array{score:int,components:array<string,int>}
     */
    private function computeAndStoreOvs(int $clientId, string $period, ?array $gscTotals): array
    {
        $in = [];
        if ($gscTotals) {
            $in['google_clicks'] = $gscTotals['clicks'];
            $in['google_impressions'] = $gscTotals['impressions'];
            $in['google_ctr'] = $gscTotals['ctr'];
        }
        // Bing-Klicks (getrackte Keywords) als grobe Näherung, falls vorhanden.
        $rank = $this->repo->rankingSummary($clientId, $period);
        if (isset($rank['bing'])) {
            $in['bing_clicks'] = (int) ($rank['bing']['clicks'] ?? 0);
        }
        // KI-Nennungen (Summe „erwähnt" über alle GEO-Kanäle).
        $geo = $this->repo->geoSummary($clientId, $period);
        $in['geo_mentions'] = array_sum(array_map(static fn($s) => (int) $s['mentioned'], $geo));
        // Social-Views (Summe der monatlichen Views je Kanal).
        $social = $this->repo->socialMonthly($clientId, $period);
        $in['social_views'] = array_sum(array_map(static fn($r) => (int) ($r['monthly_views'] ?? 0), $social));
        // Newsletter-Öffnungen (Summe über Kampagnen des Monats).
        $nl = $this->repo->newsletterCampaigns($clientId, $period, 12);
        $in['newsletter_opens'] = array_sum(array_map(static fn($r) => (int) ($r['opens'] ?? 0), $nl));

        $ovs = VisibilityScore::compute($in);
        $this->repo->saveVisibilityScore($clientId, $period, $ovs['score'], $ovs['components']);
        return $ovs;
    }

    /**
     * OVS-Abschnitt (Dach-Zahl): eine Zahl + offene Zusammensetzung je Kanal + Trend-Chart.
     * @param array{score:int,components:array<string,int>} $ovs
     */
    private function ovsSection(int $clientId, string $period, array $ovs): string
    {
        if ($ovs['score'] <= 0) {
            return ''; // ohne Daten kein Score
        }

        $md = "## Sichtbarkeits-Score (OVS)\n\n";
        $md .= "**" . number_format($ovs['score'], 0, ',', '\'') . " aktive Sichtkontakte** "
            . "im " . $this->monthLabel($period) . ".\n\n";
        $md .= $this->gray('Der Openstream Visibility Score bündelt die Sichtbarkeit über alle '
            . 'Kanäle zu einer Zahl: wie oft wurde das Unternehmen diesen Monat online aktiv '
            . 'gesehen (Klicks, Views, KI-Nennungen, Newsletter-Öffnungen). Impressionen zählen '
            . 'dabei nicht roh, sondern mit der tatsächlichen Klickrate gewichtet (also als '
            . 'erwartete Besuche), damit die Zahl ehrlich bleibt. Nennungen in KI-Antworten '
            . 'zählen ' . VisibilityScore::GEO_WEIGHT . '-fach, da eine Empfehlung durch einen '
            . 'KI-Assistenten erfahrungsgemäss wertvoller ist als ein klassischer Klick.') . "\n\n";

        // Trend-Chart (falls ≥2 Monate).
        $hist = $this->repo->visibilityScoreHistory($clientId, $period);
        if ($this->charts !== null && count($hist) >= 2) {
            $chart = $this->charts->ovsTrend($hist);
            if ($chart !== '') {
                $md .= $chart;
            }
        }

        // Offene Zusammensetzung.
        $md .= "**Zusammensetzung:**\n\n";
        $md .= "| Kanal | Kontakte |\n|---|---:|\n";
        arsort($ovs['components']);
        foreach ($ovs['components'] as $key => $val) {
            $md .= '| ' . VisibilityScore::label($key) . ' | '
                . number_format($val, 0, ',', '\'') . " |\n";
        }
        $md .= '| **Summe (OVS)** | **' . number_format($ovs['score'], 0, ',', '\'') . "** |\n\n";

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
