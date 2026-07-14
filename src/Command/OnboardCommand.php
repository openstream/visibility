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

        // --- Vorschläge generieren ---
        $io->section('Keyword- & GEO-Prompt-Vorschläge');
        $competitors = $cfg['competitors'] ?? [];
        try {
            $gen = new PromptGenerator($claude);
            $suggestions = $gen->generate($profile, $gscQueries, $competitors);
        } catch (\Throwable $e) {
            $io->error('Generierung fehlgeschlagen: ' . $e->getMessage());
            return Command::FAILURE;
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
        $md = "# Onboarding-Report — {$name} ({$domain})\n\n";
        $md .= "> Bitte prüfen und mit dem Kunden abstimmen. Nach Freigabe werden Keywords & "
            . "GEO-Prompts als `approved` übernommen; erst dann startet die Erhebung.\n\n";

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

        $md .= "## 2. Keyword-Vorschläge (Google-Rankings)\n\n";
        $md .= "| Keyword | Begründung / Quelle |\n|---|---|\n";
        foreach ($s['keywords'] as $k) {
            $md .= '| ' . $this->cell($k['keyword']) . ' | ' . $this->cell($k['reason']) . " |\n";
        }
        $md .= "\n";

        $md .= "## 3. GEO-Prompt-Vorschläge (KI-Sichtbarkeit)\n\n";
        $md .= "| Typ | Prompt | Begründung |\n|---|---|---|\n";
        foreach ($s['geo_prompts'] as $p) {
            $md .= '| ' . $this->cell($p['type']) . ' | ' . $this->cell($p['prompt']) . ' | ' . $this->cell($p['reason']) . " |\n";
        }
        $md .= "\n---\n_Vorschläge automatisch generiert aus Website-Profil + echten GSC-Signalen. "
            . "Kuratieren erwünscht._\n";

        return $md;
    }

    private function cell(string $v): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $v);
    }
}
