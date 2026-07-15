<?php

declare(strict_types=1);

namespace Openstream\Visibility\Command;

use Openstream\Visibility\App;
use Openstream\Visibility\Database\ClientRepository;
use Openstream\Visibility\Provider\BingSerpProvider;
use Openstream\Visibility\Provider\BingWmtClient;
use Openstream\Visibility\Provider\DataForSeoClient;
use Openstream\Visibility\Provider\DataForSeoSerpProvider;
use Openstream\Visibility\Provider\GscClient;
use Openstream\Visibility\Provider\GscSerpProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 2 — Wöchentliche Datenerhebung (Rankings). Liest approved Keywords, erhebt
 * Google-Rankings über GSC (gratis, eigene Property) und optional DataForSEO SERP
 * (pay-per-task, für Keywords ohne GSC-Daten). Schreibt Zeitreihe in `measurements`.
 */
#[AsCommand(name: 'collect', description: 'Wöchentliche Ranking-Erhebung für einen Kunden (Zeitreihe)')]
final class CollectCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Kunden-Slug');
        $this->addOption('serp', null, InputOption::VALUE_NONE, 'Zusätzlich DataForSEO-SERP erheben (kostet, ~5 Min)');
        $this->addOption('geo', null, InputOption::VALUE_NONE, 'GEO-Sichtbarkeit erheben (ChatGPT/Gemini/Claude, kostet pro Prompt)');
        $this->addOption('onsite', null, InputOption::VALUE_NONE, 'Onsite-Audit der wichtigsten Seiten (DataForSEO OnPage)');
        $this->addOption('offsite', null, InputOption::VALUE_NONE, 'Offsite/Backlinks-Snapshot (DataForSEO)');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'measured_at überschreiben (Y-m-d, Default heute)');
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
        $measuredAt = $input->getOption('date') ?: date('Y-m-d');

        $repo = new ClientRepository();
        try {
            $clientId = $repo->clientIdBySlug($slug);
        } catch (\Throwable $e) {
            $io->error($e->getMessage() . ' — zuerst `onboard --save` und `approve` laufen lassen.');
            return Command::FAILURE;
        }

        $keywords = $repo->approvedKeywords($clientId);
        if (!$keywords) {
            $io->error('Keine freigegebenen Keywords. Zuerst `approve --client=' . $slug . '`.');
            return Command::FAILURE;
        }
        $io->title("Collect: {$slug} — {$measuredAt}");
        $io->text(count($keywords) . ' freigegebene Keywords.');

        $total = 0;

        // --- GSC (gratis, eigene Property) ---
        $siteUrl = $cfg['gsc']['site_url'] ?? null;
        if ($siteUrl) {
            try {
                $provider = new GscSerpProvider(GscClient::fromEnv(), $siteUrl);
                $measurements = $provider->collect($keywords);
                $n = $repo->saveMeasurements($clientId, $measurements, $measuredAt);
                $io->success("GSC: {$n} Messwerte erhoben und gespeichert.");
                $total += $n;
            } catch (\Throwable $e) {
                $io->warning('GSC-Erhebung fehlgeschlagen: ' . $e->getMessage());
            }
        } else {
            $io->text('Keine GSC-Property — überspringe GSC.');
        }

        // --- Bing Webmaster Tools (gratis, eigene Property) ---
        $bingSite = $cfg['bing']['site_url'] ?? null;
        if ($bingSite) {
            try {
                $provider = new BingSerpProvider(BingWmtClient::fromEnv(), $bingSite);
                $measurements = $provider->collect($keywords);
                $n = $repo->saveMeasurements($clientId, $measurements, $measuredAt);
                $io->success("Bing: {$n} Messwerte erhoben und gespeichert.");
                $total += $n;
            } catch (\Throwable $e) {
                $io->warning('Bing-Erhebung fehlgeschlagen: ' . $e->getMessage());
            }
        } else {
            $io->text('Keine Bing-Property — überspringe Bing.');
        }

        // --- DataForSEO SERP (optional, kostet) ---
        if ($input->getOption('serp')) {
            $io->text('DataForSEO-SERP läuft (Standard-Queue, ~5 Min Wartezeit) …');
            $dfs = new DataForSeoClient();
            try {
                $provider = new DataForSeoSerpProvider($dfs, $domain);
                $measurements = $provider->collect($keywords);
                $n = $repo->saveMeasurements($clientId, $measurements, $measuredAt);
                $io->success("DataForSEO-SERP: {$n} Messwerte gespeichert.");
                $io->note(sprintf('DataForSEO-Kosten: $%.4f', $dfs->spent()));
                $total += $n;
            } catch (\Throwable $e) {
                $io->warning('DataForSEO-SERP fehlgeschlagen: ' . $e->getMessage());
            }
        }

        // --- GEO: KI-Sichtbarkeit (optional, kostet pro Prompt) ---
        if ($input->getOption('geo')) {
            $prompts = $repo->approvedGeoPrompts($clientId);
            if (!$prompts) {
                $io->warning('Keine freigegebenen GEO-Prompts — überspringe GEO.');
            } else {
                $io->section('GEO — KI-Sichtbarkeit');
                $analyzer = new \Openstream\Visibility\Provider\MentionAnalyzer(
                    $domain, $repo->brandNames($clientId), $cfg['competitors'] ?? []
                );
                // Aktive GEO-Kanäle aus der Config (kostenbewusst wählbar).
                // ChatGPT/Gemini/Claude via DataForSEO; Perplexity via Sonar (citation-native).
                $channels = $cfg['geo']['channels'] ?? ['chatgpt' => true, 'perplexity' => true];
                $active = array_keys(array_filter($channels));
                $io->text(count($prompts) . ' GEO-Prompts über: ' . implode(', ', $active) . '.');
                $dfsGeo = new DataForSeoClient();
                $providers = [];
                foreach (['chatgpt', 'gemini', 'claude'] as $ch) {
                    if (!empty($channels[$ch])) {
                        $providers[] = new \Openstream\Visibility\Provider\DataForSeoGeoProvider($dfsGeo, $analyzer, $ch);
                    }
                }
                if (!empty($channels['perplexity'])) {
                    $providers[] = new \Openstream\Visibility\Provider\PerplexityGeoProvider($analyzer);
                }
                if (!$providers) {
                    $io->warning('Keine GEO-Kanäle aktiviert (config geo.channels).');
                }
                foreach ($providers as $p) {
                    try {
                        $mentions = $p->collect($prompts);
                        $n = $repo->saveGeoMentions($clientId, $mentions, $measuredAt);
                        $mentionedCount = count(array_filter($mentions, static fn($m) => $m->mentioned));
                        $io->success(sprintf('%s: %d Prompts erhoben, in %d erwähnt.', $p->name(), $n, $mentionedCount));
                        $total += $n;
                    } catch (\Throwable $e) {
                        $io->warning($p->name() . ' fehlgeschlagen: ' . $e->getMessage());
                    }
                }

                // Google AI Overview: arbeitet mit KEYWORDS (nicht Prompts) — prüft, ob die
                // Domain in der AI-Zusammenfassung oben in den Google-Ergebnissen zitiert wird.
                if (!empty($channels['ai_overview'])) {
                    // Nur Keywords mit echtem Suchvolumen prüfen (Impressionen) — sonst zu
                    // langsam/teuer bei vielen Keywords. Fallback: alle approved Keywords.
                    $kw = $repo->keywordsWithImpressions($clientId, 30);
                    if (!$kw) {
                        $kw = $repo->approvedKeywords($clientId);
                    }
                    if ($kw) {
                        try {
                            $aio = new \Openstream\Visibility\Provider\AiOverviewProvider($dfsGeo, $domain);
                            $mentions = $aio->collect($kw);
                            $n = $repo->saveGeoMentions($clientId, $mentions, $measuredAt);
                            $citedCount = count(array_filter($mentions, static fn($m) => $m->cited));
                            $io->success(sprintf('ai_overview: %d Keywords mit AI Overview, Domain in %d zitiert.', $n, $citedCount));
                            $total += $n;
                        } catch (\Throwable $e) {
                            $io->warning('ai_overview fehlgeschlagen: ' . $e->getMessage());
                        }
                    }
                }

                if ($dfsGeo->spent() > 0) {
                    $io->note(sprintf('DataForSEO-GEO-Kosten: $%.4f (Perplexity separat via Sonar)', $dfsGeo->spent()));
                }
            }
        }

        // --- Onsite-Audit (wichtigste Seiten: key_pages + Top-Seiten aus GSC) ---
        if ($input->getOption('onsite')) {
            $urls = $this->onsiteUrls($cfg);
            $io->section('Onsite / Technisches SEO');
            $io->text(count($urls) . ' Seiten prüfen …');
            $dfsOn = new DataForSeoClient();
            try {
                $audit = (new \Openstream\Visibility\Provider\OnsiteProvider($dfsOn))->audit($urls);
                $n = $repo->saveOnsiteAudit($clientId, $audit, $measuredAt);
                $probs = array_sum(array_map(static fn($a) => count($a['problems']), $audit));
                $io->success("Onsite: {$n} Seiten geprüft, {$probs} Auffälligkeiten.");
                $io->note(sprintf('DataForSEO-OnPage-Kosten: $%.4f', $dfsOn->spent()));
            } catch (\Throwable $e) {
                $io->warning('Onsite-Audit fehlgeschlagen: ' . $e->getMessage());
            }
        }

        // --- Offsite/Backlinks-Snapshot ---
        if ($input->getOption('offsite')) {
            $io->section('Offsite / Backlinks');
            $dfsOff = new DataForSeoClient();
            try {
                $summary = (new \Openstream\Visibility\Provider\OffsiteProvider($dfsOff, $domain))->summary();
                $repo->saveBacklinks($clientId, $summary, $measuredAt);
                $io->success(sprintf('Offsite: Domain Rank %s, %s Backlinks, %s Referring Domains.',
                    $summary['domain_rank'] ?? '?', $summary['backlinks'] ?? '?', $summary['referring_domains'] ?? '?'));
                $io->note(sprintf('DataForSEO-Backlinks-Kosten: $%.4f', $dfsOff->spent()));
            } catch (\Throwable $e) {
                $io->warning('Offsite fehlgeschlagen: ' . $e->getMessage());
            }
        }

        $io->success("Fertig. {$total} Messwerte für {$measuredAt} in der DB.");
        return Command::SUCCESS;
    }

    /**
     * URLs fürs Onsite-Audit: key_pages aus der Config + Top-Seiten aus GSC (nach Klicks).
     * @param array<string,mixed> $cfg
     * @return array<int,string>
     */
    private function onsiteUrls(array $cfg): array
    {
        $urls = $cfg['key_pages'] ?? ['https://' . ($cfg['domain'] ?? '') . '/'];

        // Top-Seiten aus GSC ergänzen (nach Klicks), falls Property vorhanden.
        $siteUrl = $cfg['gsc']['site_url'] ?? null;
        if ($siteUrl) {
            try {
                $gsc = GscClient::fromEnv();
                $end = date('Y-m-d', strtotime('-3 days'));
                $start = date('Y-m-d', strtotime('-28 days'));
                $rows = $gsc->searchAnalytics($siteUrl, $start, $end, ['page'], 20);
                foreach ($rows as $r) {
                    $urls[] = $r['keys'][0];
                }
            } catch (\Throwable) {
                // ohne GSC nur key_pages
            }
        }

        // Deduplizieren, auf sinnvolle Anzahl begrenzen (Kosten/Zeit).
        return array_slice(array_values(array_unique($urls)), 0, 25);
    }
}
