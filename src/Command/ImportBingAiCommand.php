<?php

declare(strict_types=1);

namespace Openstream\Visibility\Command;

use Openstream\Visibility\App;
use Openstream\Visibility\Database\ClientRepository;
use Openstream\Visibility\Onboarding\BingAiImporter;
use Openstream\Visibility\Provider\GeoMention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Importiert den Bing "AI Performance"-Report (Copilot / Bing-AI, GEO) aus dem
 * CSV-Export in `ai_mentions`. Der Report ist Beta und hat keine API → CSV ist
 * der einzige Weg (Microsoft plant eine API „im Laufe 2026").
 *
 * Jede CSV-Zeile ist eine Grounding Query, bei der die Domain in einer KI-Antwort
 * ZITIERT wurde — Microsoft liefert keine „nicht zitiert"-Zeilen. Deshalb ist jede
 * Zeile mentioned=cited=true; eine Sichtbarkeits-RATE (erwähnt/Anfragen) gibt es
 * für diesen Kanal nicht. Wir speichern die Zitations-Anzahl je Zeile im
 * position-Feld (bei bing_ai sonst ungenutzt), damit der Report „Zitate gesamt"
 * aufsummieren kann; die Query steht im citations-Feld.
 *
 * CSV-Pfad: storage/raw/<slug>/bing_ai/<YYYY-MM>.csv
 */
#[AsCommand(name: 'import-bing-ai', description: 'Bing-AI-Performance-CSV (Copilot/GEO) in ai_mentions importieren')]
final class ImportBingAiCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Kunden-Slug');
        $this->addOption('month', null, InputOption::VALUE_REQUIRED, 'Berichtsmonat (Y-m), Default aktueller Monat');
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'CSV-Pfad (überschreibt den Standardpfad)');
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
        $month = $input->getOption('month') ?: date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $io->error('--month muss das Format Y-m haben (z.B. 2026-07).');
            return Command::INVALID;
        }

        $cfgFile = $app->configPath("clients/{$slug}.yaml");
        if (!is_file($cfgFile)) {
            $io->error("Konfiguration nicht gefunden: {$cfgFile}");
            return Command::FAILURE;
        }
        $cfg = Yaml::parseFile($cfgFile);

        $csv = $input->getOption('file') ?: $app->storagePath("raw/{$slug}/bing_ai/{$month}.csv");
        if (!is_file($csv)) {
            $io->error("Bing-AI-CSV nicht gefunden: {$csv}");
            $io->note('Export unter bing.com/webmasters → AI Performance → als CSV, dann hierher legen.');
            return Command::FAILURE;
        }

        $repo = new ClientRepository();
        try {
            $clientId = $repo->upsertFromConfig($cfg);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->title("Bing-AI-Import: {$slug} — {$month}");

        try {
            $rows = (new BingAiImporter())->citations($csv);
        } catch (\Throwable $e) {
            $io->error('CSV konnte nicht gelesen werden: ' . $e->getMessage());
            return Command::FAILURE;
        }
        if (!$rows) {
            $io->warning('Keine Zitations-Zeilen in der CSV.');
            return Command::SUCCESS;
        }

        // measured_at auf den Monatsletzten setzen (Monats-Snapshot, wie im Report erwartet).
        $measuredAt = date('Y-m-t', strtotime($month . '-01'));

        $mentions = [];
        foreach ($rows as $r) {
            $mentions[] = new GeoMention(
                engine:      'bing_ai',
                promptId:    null,
                mentioned:   true,   // jede Zeile = zitierte Query
                cited:       true,
                position:    $r['citations'],   // Zitations-Anzahl (bing_ai nutzt position sonst nicht)
                citations:   [$r['query']],     // Grounding Query als Beleg
                competitors: [],
                source:      'bing_ai_csv',
            );
        }

        $n = $repo->saveGeoBatch($clientId, $mentions, $measuredAt);
        $totalCit = array_sum(array_column($rows, 'citations'));
        $io->success("{$n} zitierte Bing-AI-Queries importiert (Zitate gesamt: {$totalCit}), Stand {$measuredAt}.");

        return Command::SUCCESS;
    }
}
