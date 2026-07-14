<?php

declare(strict_types=1);

namespace Openstream\Visibility\Command;

use Openstream\Visibility\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'migrate', description: 'DB-Schema anlegen/aktualisieren (schema.sql ausführen)')]
final class MigrateCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $app = App::get();

        $schema = $app->rootDir . '/src/Database/schema.sql';
        if (!is_file($schema)) {
            $io->error("schema.sql nicht gefunden: {$schema}");
            return Command::FAILURE;
        }

        try {
            $pdo = $app->db();
        } catch (\PDOException $e) {
            $io->error('Keine DB-Verbindung: ' . $e->getMessage());
            $io->note('Läuft DDEV? Innerhalb des Containers ausführen: ddev exec php bin/console migrate');
            return Command::FAILURE;
        }

        $sql = file_get_contents($schema);
        $pdo->exec($sql);

        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $io->success('Schema angewendet. Tabellen: ' . implode(', ', $tables));

        return Command::SUCCESS;
    }
}
