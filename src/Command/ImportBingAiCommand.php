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

        $repo = new ClientRepository();
        try {
            $clientId = $repo->upsertFromConfig($cfg);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->title("Bing-AI-Import: {$slug} — {$month}");

        // Report-Dateien finden. Bing benennt Exporte nach dem DOWNLOAD-Tag, nicht dem
        // Berichtsmonat → wir erkennen die drei Report-Typen am Namensmuster im bing_ai-Ordner
        // und ordnen sie über --month zu. --file erzwingt eine einzelne Queries-CSV.
        $dir = $app->storagePath("raw/{$slug}/bing_ai");
        $importer = new BingAiImporter();
        $measuredAt = date('Y-m-t', strtotime($month . '-01'));

        $queriesFile = $input->getOption('file')
            ?: $this->findReport($dir, ['AISearchQueries', 'SearchQueries'], $month)
            ?: (is_file("{$dir}/{$month}.csv") ? "{$dir}/{$month}.csv" : null);
        $overviewFile = $this->findReport($dir, ['AIPerformanceOverview', 'OverviewStats'], $month);
        $pagesFile    = $this->findReport($dir, ['AIPageStats', 'PageStats'], $month);

        if (!$queriesFile && !$overviewFile && !$pagesFile) {
            $io->error("Keine Bing-AI-Reports für {$month} in {$dir} gefunden.");
            $io->note('Export unter bing.com/webmasters → AI Performance. Dateien in den Ordner '
                . 'legen; der Monat wird über --month zugeordnet (Bing benennt nach Download-Tag).');
            return Command::FAILURE;
        }

        $did = [];

        // 1) Grounding Queries → ai_mentions (je Query eine Zeile).
        if ($queriesFile) {
            $rows = $importer->citations($queriesFile);
            $mentions = [];
            foreach ($rows as $r) {
                $mentions[] = new GeoMention(
                    engine: 'bing_ai', promptId: null, mentioned: true, cited: true,
                    position: $r['citations'], citations: [$r['query']], competitors: [],
                    source: 'bing_ai_csv',
                );
            }
            $n = $repo->saveGeoBatch($clientId, $mentions, $measuredAt);
            $did[] = "{$n} Grounding-Queries";
        }

        // 2) Overview + PageStats → bing_ai_stats (Monats-Snapshot).
        if ($overviewFile || $pagesFile) {
            $overview = $overviewFile ? $importer->overviewTotals($overviewFile)
                : ['citations_total' => 0, 'cited_pages_peak' => 0, 'days' => 0];
            $pages = $pagesFile ? $importer->pageStats($pagesFile) : [];
            $repo->saveBingAiStats($clientId, [
                'citations_total'  => $overview['citations_total'],
                'cited_pages_peak' => $overview['cited_pages_peak'],
                'top_pages'        => array_slice($pages, 0, 20),
            ], $measuredAt);
            $did[] = "Overview ({$overview['citations_total']} Citations gesamt)";
            $did[] = count($pages) . ' zitierte Seiten';
        }

        $io->success('Bing-AI importiert: ' . implode(', ', $did) . ", Stand {$measuredAt}.");
        return Command::SUCCESS;
    }

    /**
     * Findet die neueste passende Report-Datei im Ordner (Namensmuster-Teilstring).
     * Bevorzugt Dateien, die den Monat (MM.YYYY oder YYYY-MM) im Namen tragen.
     * @param array<int,string> $needles
     */
    private function findReport(string $dir, array $needles, string $month): ?string
    {
        if (!is_dir($dir)) {
            return null;
        }
        [$y, $m] = explode('-', $month);
        $monthTags = ["{$m}.{$y}", "{$y}-{$m}", "_{$month}"];
        $matches = [];
        foreach (glob("{$dir}/*.csv") ?: [] as $path) {
            $base = basename($path);
            foreach ($needles as $needle) {
                if (stripos($base, $needle) !== false) {
                    // Priorität: Datei mit passendem Monat im Namen zuerst.
                    $score = 0;
                    foreach ($monthTags as $tag) {
                        if (stripos($base, $tag) !== false) {
                            $score = 1;
                        }
                    }
                    $matches[] = ['path' => $path, 'score' => $score, 'mtime' => filemtime($path)];
                    break;
                }
            }
        }
        if (!$matches) {
            return null;
        }
        // Höchster Score, dann neueste Datei.
        usort($matches, static fn($a, $b) => [$b['score'], $b['mtime']] <=> [$a['score'], $a['mtime']]);
        return $matches[0]['path'];
    }
}
