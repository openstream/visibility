<?php

declare(strict_types=1);

namespace Openstream\Visibility\Command;

use Openstream\Visibility\App;
use Openstream\Visibility\Onboarding\PromptGenerator;
use Openstream\Visibility\Onboarding\WebsiteAnalyzer;
use Openstream\Visibility\Provider\ClaudeClient;
use Openstream\Visibility\Provider\ContentFetcher;
use Openstream\Visibility\Provider\DataForSeoClient;
use Openstream\Visibility\Provider\GscClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 1.5 — Onboarding: Website verstehen (Schritt 0) + Keywords & GEO-Prompts
 * generieren, als Onboarding-Report .md zur Kundenfreigabe.
 */
#[AsCommand(name: 'onboard', description: 'Website verstehen + Keywords/GEO-Prompts generieren (→ Kundenfreigabe)')]
final class OnboardCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Kunden-Slug (config/clients/<slug>.yaml)');
        $this->addOption('urls', null, InputOption::VALUE_REQUIRED, 'Kommagetrennte URLs (Default: key_pages/Startseite)');
        $this->addOption('save', null, InputOption::VALUE_NONE, 'Ergebnisse in die DB schreiben (Status pending → approve nötig)');
        $this->addOption('bing-ai', null, InputOption::VALUE_REQUIRED, 'Pfad zur Bing-AI-Report-CSV (Grounding Queries als GEO-Prompt-Saatgut)');
        $this->addOption('labs-ideas', null, InputOption::VALUE_NONE, 'DataForSEO Labs Keyword-Ideen als zusätzliches Signal (kostet ~$0.01, braucht GSC-Queries)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $app = App::get();

        $slug = $input->getOption('client');
        if (!$slug) {
            $io->error('--client=<slug> erforderlich.');
            return Command::INVALID;
        }

        $cfgFile = $app->configPath("clients/{$slug}.yaml");
        if (!is_file($cfgFile)) {
            $io->error("Konfiguration nicht gefunden: {$cfgFile}");
            return Command::FAILURE;
        }
        $cfg = Yaml::parseFile($cfgFile);
        $domain = $cfg['domain'] ?? $slug;

        // Zu analysierende URLs bestimmen. Priorität:
        // 1) --urls Override, 2) key_pages aus der Config, 3) Startseite als Fallback.
        if ($input->getOption('urls')) {
            $urls = array_map('trim', explode(',', (string) $input->getOption('urls')));
        } elseif (!empty($cfg['key_pages'])) {
            $urls = $cfg['key_pages'];
        } else {
            $urls = ["https://www.{$domain}/", "https://{$domain}/"];
        }
        $urls = array_values(array_unique($urls));

        $dfs = new DataForSeoClient();
        $claude = new ClaudeClient();

        // --- Schritt 0: Website verstehen ---
        $io->section('Schritt 0 — Website verstehen');
        $io->text("Analysiere: " . implode(', ', $urls));
        try {
            $analyzer = new WebsiteAnalyzer(new ContentFetcher($dfs), $claude);
            $result = $analyzer->analyze($domain, $urls);
            $profile = $result['profile'];
        } catch (\Throwable $e) {
            $io->error('Website-Analyse fehlgeschlagen: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $io->success('Website-Profil erstellt.');

        // --- Aussensicht: GSC-Queries (falls Property vorhanden) ---
        $gscQueries = [];
        $siteUrl = $cfg['gsc']['site_url'] ?? null;
        if ($siteUrl) {
            try {
                $gsc = GscClient::fromEnv();
                $end = date('Y-m-d', strtotime('-3 days'));
                $start = date('Y-m-d', strtotime('-90 days'));
                $rows = $gsc->searchAnalytics($siteUrl, $start, $end, ['query'], 80);
                $gscQueries = array_map(static fn($r) => $r['keys'][0], $rows);
                $io->text('GSC-Queries geladen: ' . count($gscQueries));
            } catch (\Throwable $e) {
                $io->warning('GSC nicht verfügbar: ' . $e->getMessage());
            }
        } else {
            $io->text('Keine GSC-Property in der Config — überspringe GSC-Signale.');
        }

        // --- Optional: DataForSEO Labs Keyword-Ideen als zusätzliches Keyword-Signal ---
        // Seeds = die (spezifischen) GSC-Queries; die Ideen sind ROH und streuen breit,
        // dienen daher nur als Signal für die LLM-/Nick-Kuratierung, nicht als fertige Liste.
        if ($input->getOption('labs-ideas')) {
            if ($gscQueries) {
                try {
                    $dfsIdeas = new \Openstream\Visibility\Provider\DataForSeoClient();
                    $ideas = (new \Openstream\Visibility\Provider\LabsProvider($dfsIdeas, $cfg['domain'] ?? $slug))
                        ->keywordIdeas(array_slice($gscQueries, 0, 20), 50);
                    $ideaKw = array_map(static fn($i) => $i['keyword'], $ideas);
                    $gscQueries = array_values(array_unique(array_merge($gscQueries, $ideaKw)));
                    $io->text('Labs Keyword-Ideen ergänzt: ' . count($ideaKw)
                        . sprintf(' ($%.4f)', $dfsIdeas->spent()));
                } catch (\Throwable $e) {
                    $io->warning('Labs Keyword-Ideen nicht verfügbar: ' . $e->getMessage());
                }
            } else {
                $io->text('Keine GSC-Queries als Seeds — überspringe Labs Keyword-Ideen.');
            }
        }

        // --- Optional: Bing-AI Grounding Queries (CSV) als GEO-Prompt-Saatgut ---
        $groundingQueries = [];
        if ($bingCsv = $input->getOption('bing-ai')) {
            try {
                $groundingQueries = (new \Openstream\Visibility\Onboarding\BingAiImporter())->groundingQueries($bingCsv);
                $io->text('Bing-AI Grounding Queries geladen: ' . count($groundingQueries));
            } catch (\Throwable $e) {
                $io->warning('Bing-AI-CSV nicht verwertbar: ' . $e->getMessage());
            }
        }

        // --- Vorschläge generieren ---
        $io->section('Keyword- & GEO-Prompt-Vorschläge');
        $competitors = $cfg['competitors'] ?? [];
        try {
            $gen = new PromptGenerator($claude);
            $suggestions = $gen->generate($profile, $gscQueries, $competitors, $groundingQueries);
        } catch (\Throwable $e) {
            $io->error('Generierung fehlgeschlagen: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Deterministische Plattform-Keyword-Kombinationen ergänzen (keyword_combinations
        // in der Config). Etabliert Plattform-Expertise systematisch, unabhängig vom LLM.
        $combos = (new \Openstream\Visibility\Onboarding\KeywordCombiner())->fromConfig($cfg);
        if ($combos) {
            $existing = [];
            foreach ($suggestions['keywords'] as $k) {
                $existing[mb_strtolower($k['keyword'])] = true;
            }
            $added = 0;
            foreach ($combos as $kw) {
                if (!isset($existing[$kw])) {
                    $suggestions['keywords'][] = ['keyword' => $kw, 'reason' => 'Plattform-Kombination (Config keyword_combinations)'];
                    $added++;
                }
            }
            $io->text("Plattform-Kombinationen ergänzt: +{$added} Keywords.");
        }

        // --- Onboarding-Report schreiben ---
        $dir = $app->storagePath("reports/{$slug}");
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $reportPath = "{$dir}/onboarding.md";
        file_put_contents($reportPath, $this->renderReport($cfg, $profile, $result['source_urls'], $suggestions));

        $io->success("Onboarding-Report: {$reportPath}");
        $io->note(sprintf('DataForSEO-Kosten dieses Laufs: $%.4f', $dfs->spent()));

        // --- Optional: in die DB schreiben (Status pending) ---
        if ($input->getOption('save')) {
            try {
                $repo = new \Openstream\Visibility\Database\ClientRepository();
                $clientId = $repo->upsertFromConfig($cfg);
                $repo->saveWebsiteProfile($clientId, $profile, $result['source_urls']);
                $nk = $repo->addKeywords($clientId, $suggestions['keywords']);
                $np = $repo->addGeoPrompts($clientId, $suggestions['geo_prompts']);
                $io->success("In DB gespeichert (client_id={$clientId}): +{$nk} Keywords, +{$np} GEO-Prompts, 1 Profil — Status pending.");
                $io->text("Freigeben mit:  bin/console approve --client={$slug}");
            } catch (\Throwable $e) {
                $io->error('DB-Speicherung fehlgeschlagen: ' . $e->getMessage());
                return Command::FAILURE;
            }
        } else {
            $io->text('Report prüfen. Mit --save in die DB schreiben, danach `approve` zur Freigabe.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string,mixed> $cfg
     * @param array<string,mixed> $profile
     * @param array<int,string>   $sourceUrls
     * @param array{keywords:array<int,array<string,string>>,geo_prompts:array<int,array<string,string>>} $s
     */
    private function renderReport(array $cfg, array $profile, array $sourceUrls, array $s): string
    {
        $name = $cfg['name'] ?? $cfg['slug'] ?? '';
        $domain = $cfg['domain'] ?? '';
        $md = "# Onboarding — {$name} ({$domain})\n\n";

        $md .= "## Worum es hier geht\n\n";
        $md .= "Bevor wir mit der laufenden Beobachtung Ihrer Sichtbarkeit starten, legen wir "
            . "gemeinsam fest, **was genau beobachtet werden soll**. Dieses Dokument enthält zwei "
            . "Vorschlagslisten, die wir aus Ihrer Website und echten Suchdaten abgeleitet haben. "
            . "Bitte prüfen Sie diese und geben Sie uns Rückmeldung, was passt, fehlt oder weg kann.\n\n";

        $md .= "**Zwei Arten von Suche, die wir beobachten:**\n\n";
        $md .= "- **Keywords** — Suchbegriffe, die Menschen bei **Google und Bing** eingeben. "
            . "Wir prüfen monatlich, auf welcher Position Ihre Website dafür erscheint.\n";
        $md .= "- **KI-Prompts** — typische Fragen, die Menschen **KI-Assistenten** (ChatGPT, "
            . "Perplexity, Google KI, Copilot) stellen. Wir prüfen, ob Ihre Marke in deren "
            . "Antworten **erwähnt und als Quelle genannt** wird.\n\n";

        $md .= "> **Legende zu den Quellen** (Spalte Grundlage):\n";
        $md .= "> - **Suchanfrage (Google):** ein Begriff, mit dem Nutzer Ihre Seite bei Google "
            . "bereits gefunden haben (aus der Google Search Console — Googles offiziellen Daten "
            . "zu Ihrer Website).\n";
        $md .= "> - **KI-Anfrage (Bing/Copilot):** eine echte Frage, bei der Microsofts KI "
            . "(Copilot) Ihre Website bereits als Quelle zitiert hat (aus den Bing Webmaster Tools).\n";
        $md .= "> - **Website-Inhalt:** aus dem abgeleitet, was auf Ihrer Website steht.\n\n";

        $md .= "---\n\n";
        $md .= "## 1. Haben wir die Website richtig verstanden?\n\n";
        $md .= "**Kurzbeschreibung:** " . ($profile['summary'] ?? '') . "\n\n";
        $md .= "- **Absicht:** " . ($profile['intent'] ?? '') . "\n";
        $md .= "- **Zielgruppe:** " . ($profile['audience'] ?? '') . "\n";
        $md .= "- **Region:** " . (($profile['region'] ?? '') ?: '—') . "\n";
        $md .= "- **Positionierung/USP:** " . ($profile['positioning'] ?? '') . "\n";
        $md .= "- **Eigene Marke(n):** " . implode(', ', $profile['brand_names'] ?? []) . "\n";
        $md .= "- **Leistungen:** " . implode(', ', $profile['offerings'] ?? []) . "\n";
        $md .= "- **Themen:** " . implode(', ', $profile['topics'] ?? []) . "\n";
        if (!empty($profile['mentioned_third_parties'])) {
            $md .= "- **Erwähnte Dritt-Marken/Plattformen** (nur besprochen, NICHT eigene): "
                . implode(', ', $profile['mentioned_third_parties']) . "\n";
        }
        $md .= "\n";
        $md .= "_Analysierte Seiten:_ " . implode(', ', $sourceUrls) . "\n\n";

        $md .= "## 2. Vorgeschlagene Keywords (für Google & Bing)\n\n";
        $md .= "_Suchbegriffe, deren Ranking-Position wir monatlich verfolgen._\n\n";
        $md .= "| Keyword | Warum dieser Begriff (Grundlage) |\n|---|---|\n";
        foreach ($s['keywords'] as $k) {
            $md .= '| ' . $this->cell($k['keyword']) . ' | ' . $this->cell($this->plain($k['reason'])) . " |\n";
        }
        $md .= "\n";

        $md .= "## 3. Vorgeschlagene KI-Prompts (für die KI-Sichtbarkeit)\n\n";
        $md .= "_Fragen, mit denen wir prüfen, ob KI-Assistenten Ihre Marke nennen._\n\n";
        $md .= "**Zwei Arten von Prompts:** _Kategorie_ = allgemeine Frage aus Ihrem Themenfeld "
            . "(prüft, ob Sie gegenüber Wettbewerbern auftauchen). _Marke_ = Frage direkt nach "
            . "Ihnen (prüft, ob die KI Sie korrekt kennt).\n\n";
        $md .= "| Art | Prompt (Frage an die KI) | Warum dieser Prompt (Grundlage) |\n|---|---|---|\n";
        foreach ($s['geo_prompts'] as $p) {
            $art = ($p['type'] ?? '') === 'brand' ? 'Marke' : 'Kategorie';
            $md .= '| ' . $art . ' | ' . $this->cell($p['prompt']) . ' | ' . $this->cell($this->plain($p['reason'])) . " |\n";
        }
        $md .= "\n---\n_Diese Vorschläge wurden automatisch aus Ihrer Website und echten Suchdaten "
            . "erstellt und sind als Ausgangspunkt gedacht — Ihre Rückmeldung ist ausdrücklich erwünscht._\n";

        return $md;
    }

    private function cell(string $v): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $v);
    }

    /**
     * Übersetzt technische Begriffe in den LLM-Begründungen in kundenverständliche
     * Sprache (der Onboarding-Report geht an den Kunden).
     */
    private function plain(string $v): string
    {
        $map = [
            'Bing-AI-Grounding-Query'         => 'echte KI-Anfrage (Copilot), bei der Ihre Seite zitiert wurde',
            'Bing-AI Grounding-Query'         => 'echte KI-Anfrage (Copilot), bei der Ihre Seite zitiert wurde',
            'Grounding-Query'                 => 'echte KI-Anfrage (Copilot)',
            'Grounding Query'                 => 'echte KI-Anfrage (Copilot)',
            'GSC-Query'                       => 'Suchanfrage bei Google',
            'GSC-Anfrage'                     => 'Suchanfrage bei Google',
            'GSC-Signal'                      => 'Google-Suchdaten',
            'GSC-Häufung'                     => 'häufige Google-Suchanfrage',
            'GSC '                            => 'Google-Suchanfrage ',
            "GSC '"                           => "Google-Suchanfrage '",
        ];
        return strtr($v, $map);
    }
}
