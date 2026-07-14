<?php

declare(strict_types=1);

namespace Openstream\Visibility;

use Dotenv\Dotenv;
use PDO;

/**
 * Zentrale App-Initialisierung: lädt .env, stellt Config & DB-Verbindung bereit.
 * Bewusst schlank gehalten (kein Framework, kein DI-Container).
 */
final class App
{
    private static ?App $instance = null;
    private ?PDO $pdo = null;

    private function __construct(public readonly string $rootDir)
    {
        if (is_file($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->load();
        }
        date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Zurich');
    }

    public static function boot(string $rootDir): self
    {
        return self::$instance ??= new self($rootDir);
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('App not booted. Call App::boot() first.');
        }
        return self::$instance;
    }

    public function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $default;
        return $value === '' ? $default : $value;
    }

    /** Lazily erstellte PDO-Verbindung zur MariaDB (DDEV-Defaults). */
    public function db(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $host = $this->env('DB_HOST', 'db');
        $port = $this->env('DB_PORT', '3306');
        $name = $this->env('DB_NAME', 'db');
        $dsn  = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        return $this->pdo = new PDO(
            $dsn,
            $this->env('DB_USER', 'db'),
            $this->env('DB_PASS', 'db'),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        );
    }

    public function storagePath(string $sub = ''): string
    {
        return rtrim($this->rootDir . '/storage/' . ltrim($sub, '/'), '/');
    }

    public function configPath(string $sub = ''): string
    {
        return rtrim($this->rootDir . '/config/' . ltrim($sub, '/'), '/');
    }
}
