<?php

declare(strict_types=1);

namespace Openstream\Visibility\Command;

use Openstream\Visibility\App;
use Openstream\Visibility\Database\ClientRepository;
use Openstream\Visibility\Provider\DataForSeoClient;
use Openstream\Visibility\Provider\KeywordVolumeProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Aktualisiert das CH-Suchvolumen je approved Keyword (DataForSEO Keywords Data).
 * Ein Call für den ganzen Keyword-Satz (~$0.09). Suchvolumen ändert sich langsam →
 * quartalsweise/manuell laufen lassen, NICHT bei jedem monatlichen collect.
 */
#[AsCommand(name: 'refresh-volume', description: 'CH-Suchvolumen je Keyword aktualisieren (~$0.09, quartalsweise)')]
final class RefreshVolumeCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Kunden-Slug');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $slug = $input->getOption('client');
        if (!$slug) {
            $io->error('--client=<slug> erforderlich.');
            return Command::INVALID;
        }

        $repo = new ClientRepository();
        try {
            $clientId = $repo->clientIdBySlug($slug);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $keywords = $repo->approvedKeywords($clientId);
        if (!$keywords) {
            $io->error('Keine freigegebenen Keywords.');
            return Command::FAILURE;
        }

        $io->title("Suchvolumen (CH): {$slug} — " . count($keywords) . ' Keywords');
        $dfs = new DataForSeoClient();
        try {
            $volumes = (new KeywordVolumeProvider($dfs))->volumes(array_values($keywords));
        } catch (\Throwable $e) {
            $io->error('Suchvolumen-Abruf fehlgeschlagen: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $n = $repo->saveKeywordVolumes($clientId, $volumes);
        $withVolume = count(array_filter($volumes, static fn($v) => $v['search_volume'] !== null));
        $io->success("{$n} Keywords aktualisiert, davon {$withVolume} mit messbarem Suchvolumen.");
        $io->note(sprintf('DataForSEO-Kosten: $%.4f', $dfs->spent()));

        return Command::SUCCESS;
    }
}
