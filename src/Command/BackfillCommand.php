<?php

declare(strict_types=1);

namespace Openstream\Visibility\Command;

use Openstream\Visibility\App;
use Openstream\Visibility\Database\ClientRepository;
use Openstream\Visibility\Provider\DataForSeoClient;
use Openstream\Visibility\Provider\HistoricalProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Lädt rückwirkend die monatliche Google-Sichtbarkeits-Historie (DataForSEO Labs
 * historical_rank_overview) → `visibility_history`. Einmalig pro Domain (~$0.13),
 * damit der erste Report sofort einen Verlauf zeigt statt bei null zu starten.
 */
#[AsCommand(name: 'backfill', description: 'Historische Google-Sichtbarkeit rückwirkend laden (~$0.13/Domain)')]
final class BackfillCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Kunden-Slug');
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

        $repo = new ClientRepository();
        try {
            $clientId = $repo->upsertFromConfig($cfg);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->title("Historie-Backfill: {$domain}");
        $dfs = new DataForSeoClient();
        try {
            $provider = new HistoricalProvider($dfs, $domain);
            $rows = $provider->overview();
        } catch (\Throwable $e) {
            $io->error('Historie-Abruf fehlgeschlagen: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (!$rows) {
            $io->warning('Keine historischen Daten für diese Domain (evtl. zu neu / rankt nicht im Index).');
            return Command::SUCCESS;
        }

        $n = $repo->saveVisibilityHistory($clientId, $rows);
        $first = $rows[0]['period'];
        $last = end($rows)['period'];
        $io->success("{$n} Monate Sichtbarkeits-Historie gespeichert ({$first} bis {$last}).");
        $io->note(sprintf('DataForSEO-Kosten: $%.4f', $dfs->spent()));

        return Command::SUCCESS;
    }
}
