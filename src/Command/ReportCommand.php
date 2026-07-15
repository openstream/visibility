<?php

declare(strict_types=1);

namespace Openstream\Visibility\Command;

use Openstream\Visibility\App;
use Openstream\Visibility\Database\ClientRepository;
use Openstream\Visibility\Report\ReportBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 3 — erzeugt den ausführlichen `.md`-Report (Deutsch) aus den erhobenen
 * Zeitreihen. Erste Version: Markt-Kontext + Suchmaschinen-Rankings; Onsite/Offsite/
 * GEO als „noch nicht erhoben" markiert. Executive Summary + Charts folgen.
 */
#[AsCommand(name: 'report', description: 'Monatlichen .md-Report erzeugen')]
final class ReportCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Kunden-Slug');
        $this->addOption('month', null, InputOption::VALUE_REQUIRED, 'Berichtsmonat YYYY-MM (Default: aktueller Monat)');
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
        $period = $input->getOption('month') ?: date('Y-m');

        $cfgFile = $app->configPath("clients/{$slug}.yaml");
        if (!is_file($cfgFile)) {
            $io->error("Konfiguration nicht gefunden: {$cfgFile}");
            return Command::FAILURE;
        }
        $cfg = Yaml::parseFile($cfgFile);

        $repo = new ClientRepository();
        try {
            $clientId = $repo->clientIdBySlug($slug);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // ClaudeClient für die Executive Summary (optional — ohne Key: Report ohne Summary).
        $claude = null;
        try {
            $claude = new \Openstream\Visibility\Provider\ClaudeClient();
        } catch (\Throwable $e) {
            $io->warning('Ohne Executive Summary (Anthropic nicht verfügbar): ' . $e->getMessage());
        }

        $md = (new ReportBuilder($repo, $claude))->build($clientId, $period, $cfg);

        $dir = $app->storagePath("reports/{$slug}");
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = "{$dir}/{$period}.md";
        file_put_contents($path, $md);

        $io->success("Report erstellt: {$path}");
        return Command::SUCCESS;
    }
}
