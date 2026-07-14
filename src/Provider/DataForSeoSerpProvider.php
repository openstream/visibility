<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Rankings via DataForSEO SERP API (Google CH/de). Für jedes Keyword: Position der
 * Kundendomain in den organischen Ergebnissen. Nutzt die Standard-Queue (task_post →
 * task_get, ~5 Min, günstigste Stufe $0.0006/Query). Ergänzt GSC dort, wo keine
 * GSC-Daten existieren (neue/nicht-rankende Keywords, Wettbewerbskontext).
 */
final class DataForSeoSerpProvider implements SerpProvider
{
    private const LOCATION_CH = 2756;

    public function __construct(
        private readonly DataForSeoClient $dfs,
        private readonly string $domain,           // Kundendomain (ohne Schema), z.B. openstream.ch
        private readonly string $languageCode = 'de',
        private readonly int $pollSeconds = 20,
        private readonly int $maxWaitSeconds = 420,
    ) {}

    public function name(): string
    {
        return 'dataforseo_serp';
    }

    public function collect(array $keywords): array
    {
        if (!$keywords) {
            return [];
        }

        // 1) Alle Keywords als Tasks posten. tag = keywordId zur späteren Zuordnung.
        $tasks = [];
        foreach ($keywords as $keywordId => $keyword) {
            $tasks[] = [
                'keyword'       => $keyword,
                'language_code' => $this->languageCode,
                'location_code' => self::LOCATION_CH,
                'tag'           => (string) $keywordId,
            ];
        }
        $this->dfs->post('serp/google/organic/task_post', $tasks);

        // 2) Warten, bis die Tasks fertig sind (tasks_ready), Ergebnisse abholen.
        $normalized = self::domainNeedle($this->domain);
        $out = [];
        $collectedIds = [];
        $deadline = time() + $this->maxWaitSeconds;

        while (time() < $deadline && count($collectedIds) < count($keywords)) {
            $this->sleep($this->pollSeconds);
            $ready = $this->dfs->get('serp/google/organic/tasks_ready');
            foreach ($ready['tasks'][0]['result'] ?? [] as $r) {
                $id = $r['id'] ?? null;
                if ($id === null || isset($collectedIds[$id])) {
                    continue;
                }
                $collectedIds[$id] = true;
                $res = $this->dfs->get("serp/google/organic/task_get/regular/{$id}");
                $task = $res['tasks'][0] ?? [];
                $keywordId = isset($task['data']['tag']) ? (int) $task['data']['tag'] : null;
                $items = $task['result'][0]['items'] ?? [];

                [$position, $url] = self::findDomain($items, $normalized);
                $out[] = new Measurement(
                    engine:      'google',
                    keywordId:   $keywordId,
                    position:    $position,
                    url:         $url,
                    impressions: null,
                    clicks:      null,
                    ctr:         null,
                    source:      'dataforseo_serp',
                );
            }
        }
        return $out;
    }

    /**
     * Findet die erste organische Position der Domain in SERP-Items.
     * public static = ohne Instanz/API testbar. @return array{0:?float,1:?string}
     *
     * @param array<int,array<string,mixed>> $items
     */
    public static function findDomain(array $items, string $needle): array
    {
        foreach ($items as $it) {
            if (($it['type'] ?? '') !== 'organic') {
                continue;
            }
            $d = mb_strtolower((string) ($it['domain'] ?? ''));
            if ($d !== '' && str_contains($d, $needle)) {
                return [(float) ($it['rank_absolute'] ?? $it['rank_group'] ?? 0), $it['url'] ?? null];
            }
        }
        return [null, null]; // nicht in den Ergebnissen gefunden
    }

    /** Normalisiert eine Domain zum Vergleichs-Needle (ohne Schema/www/Trailing-Slash). */
    public static function domainNeedle(string $domain): string
    {
        $d = strtolower($domain);
        $d = preg_replace('#^https?://#', '', $d);
        $d = preg_replace('#^www\.#', '', (string) $d);
        return rtrim((string) $d, '/');
    }

    /** Kapselt sleep, damit Tests es überschreiben können. */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
