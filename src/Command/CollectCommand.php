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

        $io->success("Fertig. {$total} Messwerte für {$measuredAt} in der DB.");
        return Command::SUCCESS;
    }
}
