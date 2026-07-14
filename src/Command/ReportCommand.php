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
 * Phase 3 — Monatlicher Report: aus den wöchentlichen Zeitreihen einen
 * ausführlichen .md-Report (Deutsch) mit Diagrammen (Momentaufnahme + Verlauf)
 * + Executive Summary (per LLM) erzeugen. Implementierung folgt in Phase 3.
 */
#[AsCommand(name: 'report', description: 'Monatlichen .md-Report + Charts + Executive Summary erzeugen')]
final class ReportCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Kunden-Slug');
        $this->addOption('month', null, InputOption::VALUE_REQUIRED, 'Berichtsmonat YYYY-MM (Default: Vormonat)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $client = $input->getOption('client');
        if (!$client) {
            $io->error('--client=<slug> erforderlich.');
            return Command::INVALID;
        }
        $month = $input->getOption('month') ?? date('Y-m', strtotime('first day of last month'));

        $io->title("Report: {$client} — {$month}");
        $io->note('Noch nicht implementiert (Phase 3). Erzeugt Charts, .md-Report (Deutsch) '
            . 'und Executive Summary aus den Zeitreihen.');

        return Command::SUCCESS;
    }
}
