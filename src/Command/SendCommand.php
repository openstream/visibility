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
 * Phase 4 — Versand: erstellt einen Gmail-Draft (Executive Summary als Body,
 * .md-Report als Anhang) zur Freigabe durch Nick. Kein automatischer Direktversand.
 * Implementierung folgt in Phase 4.
 */
#[AsCommand(name: 'send', description: 'Report als Gmail-Draft zur Freigabe vorbereiten')]
final class SendCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Kunden-Slug');
        $this->addOption('month', null, InputOption::VALUE_REQUIRED, 'Berichtsmonat YYYY-MM');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, keinen Draft erstellen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $client = $input->getOption('client');
        if (!$client) {
            $io->error('--client=<slug> erforderlich.');
            return Command::INVALID;
        }

        $io->title("Send: {$client}");
        $io->note('Noch nicht implementiert (Phase 4). Erstellt einen Gmail-Draft zur Freigabe.');

        return Command::SUCCESS;
    }
}
