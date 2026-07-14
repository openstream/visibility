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
 * Phase 1.5 — Onboarding: Keywords & GEO-Prompts aus echten Signalen generieren
 * (GSC/Bing-Grounding/DataForSEO/Website), per LLM vorschlagen, Kunde gibt frei.
 * Implementierung folgt in Phase 1.5; hier nur die CLI-Signatur.
 */
#[AsCommand(name: 'onboard', description: 'Keywords + GEO-Prompts für eine Domain generieren (→ Kundenfreigabe)')]
final class OnboardCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Kunden-Slug (config/clients/<slug>.yaml)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $client = $input->getOption('client');
        if (!$client) {
            $io->error('--client=<slug> erforderlich.');
            return Command::INVALID;
        }

        $io->title("Onboarding: {$client}");
        $io->note('Noch nicht implementiert (Phase 1.5). Geplanter Ablauf: Rohsignale sammeln '
            . '→ LLM-Vorschlag (Keywords + 8 GEO-Prompts) → Nick kuratiert → Kundenfreigabe → DB.');

        return Command::SUCCESS;
    }
}
