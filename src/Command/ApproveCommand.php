<?php

declare(strict_types=1);

namespace Openstream\Visibility\Command;

use Openstream\Visibility\Database\ClientRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Phase 1.5 — gibt die per `onboard --save` gespeicherten Keywords, GEO-Prompts und
 * das Website-Profil frei (Status approved + Datum). Erst danach darf `collect` laufen.
 */
#[AsCommand(name: 'approve', description: 'Onboarding-Ergebnisse (Keywords/GEO-Prompts/Profil) freigeben')]
final class ApproveCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Kunden-Slug');
        $this->addOption('show', null, InputOption::VALUE_NONE, 'Nur Zählstand anzeigen, nichts freigeben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = $input->getOption('client');
        if (!$slug) {
            $io->error('--client=<slug> erforderlich.');
            return Command::INVALID;
        }

        try {
            $repo = new ClientRepository();
            $clientId = $repo->clientIdBySlug($slug);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $before = $repo->counts($clientId);
        $io->section("Status {$slug} (client_id={$clientId})");
        $io->listing([
            "Keywords: {$before['keywords_approved']} freigegeben, {$before['keywords_pending']} offen",
            "GEO-Prompts: {$before['prompts_approved']} freigegeben, {$before['prompts_pending']} offen",
            "Profile offen: {$before['profile_pending']}",
        ]);

        if ($input->getOption('show')) {
            return Command::SUCCESS;
        }

        if ($before['keywords_pending'] + $before['prompts_pending'] + $before['profile_pending'] === 0) {
            $io->text('Nichts freizugeben — alles bereits freigegeben.');
            return Command::SUCCESS;
        }

        $res = $repo->approveAll($clientId);
        $io->success(sprintf(
            'Freigegeben: %d Keywords, %d GEO-Prompts, %d Profil(e). `collect` kann jetzt laufen.',
            $res['keywords'],
            $res['prompts'],
            $res['profiles'],
        ));

        return Command::SUCCESS;
    }
}
