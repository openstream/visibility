<?php

declare(strict_types=1);

namespace Openstream\Visibility\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Phase 2 — Wöchentliche Datenerhebung über APIs (kein eigener Crawler):
 * Rankings (GSC/DataForSEO), Onsite (DataForSEO OnPage/PageSpeed/CrUX),
 * Offsite (DataForSEO Backlinks), GEO (OpenAI/DataForSEO/Perplexity).
 * Schreibt Zeitreihen mit measured_at in die DB. Implementierung folgt in Phase 2.
 */
#[AsCommand(name: 'collect', description: 'Wöchentliche Datenerhebung für einen Kunden (Zeitreihe)')]
final class CollectCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Kunden-Slug');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $client = $input->getOption('client');
        if (!$client) {
            $io->error('--client=<slug> erforderlich.');
            return Command::INVALID;
        }

        $io->title("Collect: {$client}");
        $io->note('Noch nicht implementiert (Phase 2). Erhebt Rankings/Onsite/Offsite/GEO '
            . 'über APIs und schreibt Messwerte mit Datum in die DB.');

        return Command::SUCCESS;
    }
}
