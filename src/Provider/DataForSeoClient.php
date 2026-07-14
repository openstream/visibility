<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Openstream\Visibility\App;

/**
 * Generischer DataForSEO-Client (REST, Basic Auth Login+Passwort).
 * Bewusst endpoint-agnostisch: Pfad + Payload als Parameter, damit neue API-Gruppen
 * (SERP, OnPage, Backlinks, AI Optimization, Keywords Data, Labs ...) ohne Umbau
 * andocken. Gemeinsames Auth, Retry, Kosten-Logging.
 *
 * DataForSEO-Aufrufmuster: POST an einen Endpoint mit einem Array aus Task-Objekten,
 * Antwort enthält tasks[]. Standard-Queue = task_post (~5 Min), Ergebnis via task_get.
 * Live-Endpoints liefern sofort, sind aber teurer.
 */
final class DataForSeoClient
{
    private const BASE = 'https://api.dataforseo.com/';

    private Client $http;
    private float $spent = 0.0;

    public function __construct(?string $login = null, ?string $password = null)
    {
        $app = App::get();
        $login ??= $app->env('DATAFORSEO_LOGIN');
        $password ??= $app->env('DATAFORSEO_PASSWORD');

        if (!$login || !$password) {
            throw new \RuntimeException('DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD fehlen in .env');
        }

        $this->http = new Client([
            'base_uri' => self::BASE,
            'auth'     => [$login, $password],
            'timeout'  => 120,
            'headers'  => ['Content-Type' => 'application/json'],
        ]);
    }

    /**
     * POST an einen Endpoint. $tasks ist das Array der Task-Objekte (wird als
     * JSON-Body gesendet). Gibt die dekodierte Antwort zurück.
     *
     * @param array<int,array<string,mixed>> $tasks
     * @return array<string,mixed>
     */
    public function post(string $endpoint, array $tasks): array
    {
        return $this->request('POST', $endpoint, $tasks);
    }

    /** GET an einen Endpoint (z.B. Metadaten, task_get, tasks_ready). */
    public function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint, null);
    }

    /**
     * @param array<int,array<string,mixed>>|null $tasks
     * @return array<string,mixed>
     */
    private function request(string $method, string $endpoint, ?array $tasks, int $attempt = 1): array
    {
        $path = 'v3/' . ltrim($endpoint, '/');
        try {
            $options = [];
            if ($tasks !== null) {
                $options['json'] = $tasks;
            }
            $res = $this->http->request($method, $path, $options);
            $data = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);

            // Kosten mitzählen (Feld "cost" auf Top-Level).
            if (isset($data['cost']) && is_numeric($data['cost'])) {
                $this->spent += (float) $data['cost'];
            }

            $this->assertOk($data, $endpoint);
            return $data;
        } catch (GuzzleException $e) {
            // Einfacher Retry bei transienten Netzwerk-/5xx-Fehlern.
            if ($attempt < 3) {
                usleep(400_000 * $attempt);
                return $this->request($method, $endpoint, $tasks, $attempt + 1);
            }
            throw new \RuntimeException("DataForSEO {$endpoint} fehlgeschlagen: " . $e->getMessage(), 0, $e);
        }
    }

    /** DataForSEO liefert HTTP 200 auch bei Auth-/Verifizierungsfehlern → status_code prüfen. */
    private function assertOk(array $data, string $endpoint): void
    {
        $code = $data['status_code'] ?? null;
        // 20000 = Ok. task-level Fehler prüfen wir beim Auswerten, hier nur Top-Level.
        if ($code !== null && (int) $code >= 40000) {
            throw new \RuntimeException(
                "DataForSEO {$endpoint}: {$code} — " . ($data['status_message'] ?? 'unbekannter Fehler')
            );
        }
    }

    /** Summe der bisher in dieser Instanz angefallenen API-Kosten (USD). */
    public function spent(): float
    {
        return $this->spent;
    }
}
