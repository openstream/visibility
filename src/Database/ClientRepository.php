<?php

declare(strict_types=1);

namespace Openstream\Visibility\Database;

use Openstream\Visibility\App;
use PDO;

/**
 * DB-Zugriff für Kunden, Website-Profile, Keywords und GEO-Prompts.
 * Schlank gehalten (kein ORM). Kapselt Upsert des Kunden aus der YAML-Config und
 * das Speichern/Freigeben der Onboarding-Ergebnisse.
 */
final class ClientRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? App::get()->db();
    }

    /**
     * Legt den Kunden anhand der YAML-Config an oder aktualisiert ihn; gibt die client_id zurück.
     *
     * @param array<string,mixed> $cfg
     */
    public function upsertFromConfig(array $cfg): int
    {
        $slug = (string) ($cfg['slug'] ?? '');
        if ($slug === '') {
            throw new \InvalidArgumentException('Config ohne slug.');
        }
        $locale = $cfg['locale'] ?? [];
        $params = [
            'slug'      => $slug,
            'name'      => (string) ($cfg['name'] ?? $slug),
            'domain'    => (string) ($cfg['domain'] ?? ''),
            'country'   => (string) ($locale['country'] ?? 'Switzerland'),
            'gl'        => (string) ($locale['gl'] ?? 'ch'),
            'languages' => implode(',', (array) ($locale['languages'] ?? ['de'])),
            'region'    => $locale['region'] ?? null,
            'recipient' => $cfg['report']['recipient'] ?? null,
        ];

        $this->db->prepare(
            'INSERT INTO clients (slug, name, domain, country, gl, languages, region, recipient)
             VALUES (:slug, :name, :domain, :country, :gl, :languages, :region, :recipient)
             ON DUPLICATE KEY UPDATE
               name=VALUES(name), domain=VALUES(domain), country=VALUES(country),
               gl=VALUES(gl), languages=VALUES(languages), region=VALUES(region),
               recipient=VALUES(recipient)'
        )->execute($params);

        return $this->clientIdBySlug($slug);
    }

    public function clientIdBySlug(string $slug): int
    {
        $stmt = $this->db->prepare('SELECT id FROM clients WHERE slug = ?');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException("Kunde nicht in DB: {$slug} (zuerst onboard --save laufen lassen).");
        }
        return (int) $id;
    }

    /**
     * Speichert ein neues Website-Profil (Status pending) und gibt dessen id zurück.
     *
     * @param array<string,mixed> $profile
     * @param array<int,string>   $sourceUrls
     */
    public function saveWebsiteProfile(int $clientId, array $profile, array $sourceUrls): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO website_profiles
               (client_id, summary, intent, offerings, audience, region, positioning,
                brand_names, topics, source_urls, raw)
             VALUES
               (:client_id, :summary, :intent, :offerings, :audience, :region, :positioning,
                :brand_names, :topics, :source_urls, :raw)'
        );
        $stmt->execute([
            'client_id'   => $clientId,
            'summary'     => $profile['summary'] ?? null,
            'intent'      => $profile['intent'] ?? null,
            'offerings'   => $this->json($profile['offerings'] ?? []),
            'audience'    => $profile['audience'] ?? null,
            'region'      => $profile['region'] ?? null,
            'positioning' => $profile['positioning'] ?? null,
            'brand_names' => $this->json($profile['brand_names'] ?? []),
            'topics'      => $this->json($profile['topics'] ?? []),
            'source_urls' => $this->json($sourceUrls),
            'raw'         => $this->json($profile),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Fügt Keyword-Vorschläge hinzu (idempotent pro client+keyword; Status pending).
     *
     * @param array<int,array<string,string>> $keywords  je Eintrag ['keyword'=>..., 'reason'=>...]
     * @return int Anzahl neu eingefügter Keywords
     */
    public function addKeywords(int $clientId, array $keywords): int
    {
        $existing = $this->existingSet('SELECT keyword FROM keywords WHERE client_id = ?', $clientId);
        $stmt = $this->db->prepare('INSERT INTO keywords (client_id, keyword) VALUES (?, ?)');
        $n = 0;
        foreach ($keywords as $k) {
            $kw = trim((string) ($k['keyword'] ?? ''));
            if ($kw === '' || isset($existing[mb_strtolower($kw)])) {
                continue;
            }
            $stmt->execute([$clientId, $kw]);
            $existing[mb_strtolower($kw)] = true;
            $n++;
        }
        return $n;
    }

    /**
     * Fügt GEO-Prompt-Vorschläge hinzu (idempotent pro client+prompt; Status pending).
     *
     * @param array<int,array<string,string>> $prompts  je Eintrag ['type'=>category|brand, 'prompt'=>...]
     * @return int Anzahl neu eingefügter Prompts
     */
    public function addGeoPrompts(int $clientId, array $prompts, string $source = 'onboarding'): int
    {
        $existing = $this->existingSet('SELECT prompt FROM geo_prompts WHERE client_id = ?', $clientId);
        $stmt = $this->db->prepare('INSERT INTO geo_prompts (client_id, type, prompt, source) VALUES (?, ?, ?, ?)');
        $n = 0;
        foreach ($prompts as $p) {
            $text = trim((string) ($p['prompt'] ?? ''));
            $type = ($p['type'] ?? 'category') === 'brand' ? 'brand' : 'category';
            if ($text === '' || isset($existing[mb_strtolower($text)])) {
                continue;
            }
            $stmt->execute([$clientId, $type, $text, $source]);
            $existing[mb_strtolower($text)] = true;
            $n++;
        }
        return $n;
    }

    /** Gibt alle pending/approved Keywords bzw. Prompts zurück (für Anzeige/Approve). */
    public function counts(int $clientId): array
    {
        $q = fn(string $sql) => (int) $this->db->query(sprintf($sql, $clientId))->fetchColumn();
        return [
            'keywords_pending'   => $q('SELECT COUNT(*) FROM keywords WHERE client_id=%d AND approved=0'),
            'keywords_approved'  => $q('SELECT COUNT(*) FROM keywords WHERE client_id=%d AND approved=1'),
            'prompts_pending'    => $q('SELECT COUNT(*) FROM geo_prompts WHERE client_id=%d AND approved=0'),
            'prompts_approved'   => $q('SELECT COUNT(*) FROM geo_prompts WHERE client_id=%d AND approved=1'),
            'profile_pending'    => $q('SELECT COUNT(*) FROM website_profiles WHERE client_id=%d AND approved=0'),
        ];
    }

    /**
     * Gibt Keywords/Prompts/Profil frei (approved=1 + Datum). Gibt Anzahl je Kategorie zurück.
     * @return array{keywords:int,prompts:int,profiles:int}
     */
    public function approveAll(int $clientId): array
    {
        $now = date('Y-m-d H:i:s');
        $upd = function (string $table) use ($clientId, $now): int {
            $stmt = $this->db->prepare(
                "UPDATE {$table} SET approved=1, approved_at=:now WHERE client_id=:cid AND approved=0"
            );
            $stmt->execute(['now' => $now, 'cid' => $clientId]);
            return $stmt->rowCount();
        };
        return [
            'keywords' => $upd('keywords'),
            'prompts'  => $upd('geo_prompts'),
            'profiles' => $upd('website_profiles'),
        ];
    }

    /** @return array<int,string> approved Keywords (id => keyword) */
    public function approvedKeywords(int $clientId): array
    {
        $stmt = $this->db->prepare('SELECT id, keyword FROM keywords WHERE client_id=? AND approved=1 ORDER BY id');
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Speichert die Ranking-Schwierigkeit (0-100) je Keyword (DataForSEO Labs).
     * @param array<string,?int> $difficulties keyword(lower) => difficulty
     * @return int Anzahl aktualisierter Keywords
     */
    public function saveKeywordDifficulty(int $clientId, array $difficulties): int
    {
        $upd = $this->db->prepare(
            'UPDATE keywords SET difficulty=:d WHERE client_id=:cid AND LOWER(keyword)=:kw'
        );
        $n = 0;
        foreach ($difficulties as $kw => $d) {
            $upd->execute(['d' => $d, 'cid' => $clientId, 'kw' => $kw]);
            $n += $upd->rowCount();
        }
        return $n;
    }

    /**
     * Speichert den Labs-Monats-Snapshot (ranked_keywords + relevant_pages).
     * @param array{ranked_total:int,ranked_top:array<int,mixed>,top_pages:array<int,mixed>} $s
     */
    public function saveLabsSnapshot(int $clientId, array $s, string $measuredAt): void
    {
        $this->db->prepare(
            'INSERT INTO labs_snapshots (client_id, ranked_total, ranked_top, top_pages, source, measured_at)
             VALUES (:cid, :rt, :rtop, :tp, :src, :mdate)
             ON DUPLICATE KEY UPDATE ranked_total=VALUES(ranked_total),
               ranked_top=VALUES(ranked_top), top_pages=VALUES(top_pages)'
        )->execute([
            'cid' => $clientId, 'rt' => $s['ranked_total'] ?? null,
            'rtop' => $this->json($s['ranked_top'] ?? []), 'tp' => $this->json($s['top_pages'] ?? []),
            'src' => 'dataforseo_labs', 'mdate' => $measuredAt,
        ]);
    }

    /**
     * Labs-Monats-Snapshot (jüngster im Monat) für den Report.
     * @return array{ranked_total:?int,ranked_top:array<int,array<string,mixed>>,top_pages:array<int,array<string,mixed>>}|null
     */
    public function labsSnapshot(int $clientId, string $period): ?array
    {
        [$start, $end] = $this->monthRange($period);
        $stmt = $this->db->prepare(
            'SELECT ranked_total, ranked_top, top_pages FROM labs_snapshots
             WHERE client_id=? AND measured_at BETWEEN ? AND ? ORDER BY measured_at DESC LIMIT 1'
        );
        $stmt->execute([$clientId, $start, $end]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }
        return [
            'ranked_total' => $r['ranked_total'] !== null ? (int) $r['ranked_total'] : null,
            'ranked_top'   => $r['ranked_top'] ? (json_decode((string) $r['ranked_top'], true) ?: []) : [],
            'top_pages'    => $r['top_pages'] ? (json_decode((string) $r['top_pages'], true) ?: []) : [],
        ];
    }

    /**
     * Speichert CH-Suchvolumen/Wettbewerb/CPC je Keyword (aus KeywordVolumeProvider).
     * Matcht case-insensitive über den Keyword-Text. Nur approved Keywords.
     * @param array<string,array{search_volume:?int,competition:?string,cpc:?float}> $volumes keyword(lower)=>Werte
     * @return int Anzahl aktualisierter Keywords
     */
    public function saveKeywordVolumes(int $clientId, array $volumes): int
    {
        $upd = $this->db->prepare(
            'UPDATE keywords SET search_volume=:sv, competition=:comp, cpc=:cpc, volume_updated_at=NOW()
             WHERE client_id=:cid AND LOWER(keyword)=:kw'
        );
        $n = 0;
        foreach ($volumes as $kw => $v) {
            $upd->execute([
                'sv' => $v['search_volume'], 'comp' => $v['competition'], 'cpc' => $v['cpc'],
                'cid' => $clientId, 'kw' => $kw,
            ]);
            $n += $upd->rowCount();
        }
        return $n;
    }

    /**
     * Keywords mit tatsächlichen Impressionen im jüngsten Erhebungslauf (= echtes
     * Suchvolumen). Für AI-Overview-Checks, damit nicht alle Keywords (langsam/teuer)
     * geprüft werden, sondern nur die relevanten. @return array<int,string> id => keyword
     */
    public function keywordsWithImpressions(int $clientId, int $limit = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT k.id, k.keyword
             FROM keywords k
             JOIN measurements m ON m.keyword_id = k.id
             WHERE k.client_id = ? AND k.approved = 1 AND m.impressions > 0
             ORDER BY m.impressions DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @return array<int,string> approved GEO-Prompts (id => prompt) */
    public function approvedGeoPrompts(int $clientId): array
    {
        $stmt = $this->db->prepare('SELECT id, prompt FROM geo_prompts WHERE client_id=? AND approved=1 ORDER BY id');
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Eigene Marken aus dem jüngsten Website-Profil. @return array<int,string> */
    public function brandNames(int $clientId): array
    {
        $stmt = $this->db->prepare('SELECT brand_names FROM website_profiles WHERE client_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$clientId]);
        $json = $stmt->fetchColumn();
        return $json ? (json_decode((string) $json, true) ?: []) : [];
    }

    /**
     * Speichert einen Satz gleichartiger GEO-Messwerte, die NICHT an einzelne Prompts
     * gebunden sind (prompt_id=null), z.B. den Bing-AI-CSV-Import: eine Zeile je zitierter
     * Query. Idempotent auf Batch-Ebene — löscht EINMAL alle Zeilen des Monats für
     * (engine, source) und fügt dann alle neu ein. (saveGeoMentions löscht pro Zeile über
     * prompt_id und würde bei durchweg NULL-prompt_id alle bis auf die letzte wieder killen.)
     *
     * @param array<int,\Openstream\Visibility\Provider\GeoMention> $mentions
     * @return int Anzahl geschriebener Zeilen
     */
    public function saveGeoBatch(int $clientId, array $mentions, string $measuredAt): int
    {
        if (!$mentions) {
            return 0;
        }
        $engine = $mentions[0]->engine;
        $source = $mentions[0]->source;

        $this->db->prepare(
            'DELETE FROM ai_mentions
             WHERE client_id=:cid AND engine=:engine AND source=:source AND measured_at=:mdate'
        )->execute(['cid' => $clientId, 'engine' => $engine, 'source' => $source, 'mdate' => $measuredAt]);

        $ins = $this->db->prepare(
            'INSERT INTO ai_mentions
               (client_id, prompt_id, engine, mentioned, position, cited, citations, competitors, source, measured_at)
             VALUES
               (:cid, :pid, :engine, :mentioned, :position, :cited, :citations, :competitors, :source, :mdate)'
        );
        $n = 0;
        foreach ($mentions as $m) {
            $ins->execute([
                'cid' => $clientId, 'pid' => $m->promptId, 'engine' => $m->engine,
                'mentioned' => $m->mentioned ? 1 : 0, 'position' => $m->position,
                'cited' => $m->cited ? 1 : 0,
                'citations' => $this->json($m->citations),
                'competitors' => $this->json($m->competitors),
                'source' => $m->source, 'mdate' => $measuredAt,
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * Speichert GEO-Messwerte (idempotent pro Tag/Engine/Prompt/Quelle).
     * @param array<int,\Openstream\Visibility\Provider\GeoMention> $mentions
     * @return int Anzahl geschriebener Zeilen
     */
    public function saveGeoMentions(int $clientId, array $mentions, string $measuredAt): int
    {
        $del = $this->db->prepare(
            'DELETE FROM ai_mentions
             WHERE client_id=:cid AND engine=:engine AND source=:source
               AND measured_at=:mdate AND prompt_id <=> :pid'
        );
        $ins = $this->db->prepare(
            'INSERT INTO ai_mentions
               (client_id, prompt_id, engine, mentioned, position, cited, citations, competitors, source, measured_at)
             VALUES
               (:cid, :pid, :engine, :mentioned, :position, :cited, :citations, :competitors, :source, :mdate)'
        );
        $n = 0;
        foreach ($mentions as $m) {
            $del->execute([
                'cid' => $clientId, 'engine' => $m->engine, 'source' => $m->source,
                'mdate' => $measuredAt, 'pid' => $m->promptId,
            ]);
            $ins->execute([
                'cid' => $clientId, 'pid' => $m->promptId, 'engine' => $m->engine,
                'mentioned' => $m->mentioned ? 1 : 0, 'position' => $m->position,
                'cited' => $m->cited ? 1 : 0,
                'citations' => $this->json($m->citations),
                'competitors' => $this->json($m->competitors),
                'source' => $m->source, 'mdate' => $measuredAt,
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * Bing-AI/Copilot-Kennzahlen für einen Monat: Anzahl zitierter Grounding Queries
     * und Summe der Zitationen (position-Feld trägt bei bing_ai die Zitations-Anzahl).
     * Anders als die Chat-Kanäle gibt es hier keine „nicht zitiert"-Grundgesamtheit,
     * also KEINE Rate. Nutzt den jüngsten Stand im Monat.
     * @return array{queries:int,citations:int}|null
     */
    public function bingAiSummary(int $clientId, string $period): ?array
    {
        [$start, $end] = $this->monthRange($period);
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) queries, COALESCE(SUM(position),0) citations
             FROM ai_mentions
             WHERE client_id = ? AND engine = 'bing_ai' AND measured_at BETWEEN ? AND ?
               AND measured_at = (SELECT MAX(measured_at) FROM ai_mentions a2
                                  WHERE a2.client_id = ai_mentions.client_id
                                    AND a2.engine = 'bing_ai' AND a2.measured_at BETWEEN ? AND ?)"
        );
        $stmt->execute([$clientId, $start, $end, $start, $end]);
        $r = $stmt->fetch();
        if (!$r || (int) $r['queries'] === 0) {
            return null;
        }
        return ['queries' => (int) $r['queries'], 'citations' => (int) $r['citations']];
    }

    /**
     * Top-Grounding-Queries von Bing-AI im Monat (aus ai_mentions; position = Citation-Anzahl),
     * absteigend nach Zitationen.
     * @return array<int,array{query:string,citations:int}>
     */
    public function bingAiQueries(int $clientId, string $period, int $limit = 10): array
    {
        [$start, $end] = $this->monthRange($period);
        $stmt = $this->db->prepare(
            "SELECT citations, position FROM ai_mentions
             WHERE client_id=? AND engine='bing_ai' AND measured_at BETWEEN ? AND ?
               AND measured_at=(SELECT MAX(measured_at) FROM ai_mentions a2
                                WHERE a2.client_id=ai_mentions.client_id AND a2.engine='bing_ai'
                                  AND a2.measured_at BETWEEN ? AND ?)
             ORDER BY position DESC LIMIT {$limit}"
        );
        $stmt->execute([$clientId, $start, $end, $start, $end]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $cits = json_decode((string) ($r['citations'] ?? '[]'), true) ?: [];
            $query = is_array($cits) && isset($cits[0]) ? (string) $cits[0] : '';
            if ($query !== '') {
                $out[] = ['query' => $query, 'citations' => (int) $r['position']];
            }
        }
        return $out;
    }

    /**
     * Speichert den Bing-AI-Monats-Snapshot (Gesamt-Citations + meistzitierte Seiten).
     * Idempotent pro (client, measured_at).
     * @param array{citations_total:int,cited_pages_peak:int,top_pages:array<int,mixed>} $s
     */
    public function saveBingAiStats(int $clientId, array $s, string $measuredAt): void
    {
        $this->db->prepare(
            'INSERT INTO bing_ai_stats (client_id, citations_total, cited_pages_peak, top_pages, source, measured_at)
             VALUES (:cid, :ct, :cp, :tp, :src, :mdate)
             ON DUPLICATE KEY UPDATE citations_total=VALUES(citations_total),
               cited_pages_peak=VALUES(cited_pages_peak), top_pages=VALUES(top_pages)'
        )->execute([
            'cid' => $clientId, 'ct' => $s['citations_total'] ?? null,
            'cp' => $s['cited_pages_peak'] ?? null, 'tp' => $this->json($s['top_pages'] ?? []),
            'src' => 'bing_ai_csv', 'mdate' => $measuredAt,
        ]);
    }

    /**
     * Bing-AI-Monats-Snapshot (jüngster im Monat) für den Report.
     * @return array{citations_total:?int,cited_pages_peak:?int,top_pages:array<int,array<string,mixed>>}|null
     */
    public function bingAiStats(int $clientId, string $period): ?array
    {
        [$start, $end] = $this->monthRange($period);
        $stmt = $this->db->prepare(
            'SELECT citations_total, cited_pages_peak, top_pages FROM bing_ai_stats
             WHERE client_id=? AND measured_at BETWEEN ? AND ? ORDER BY measured_at DESC LIMIT 1'
        );
        $stmt->execute([$clientId, $start, $end]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }
        return [
            'citations_total'  => $r['citations_total'] !== null ? (int) $r['citations_total'] : null,
            'cited_pages_peak' => $r['cited_pages_peak'] !== null ? (int) $r['cited_pages_peak'] : null,
            'top_pages'        => $r['top_pages'] ? (json_decode((string) $r['top_pages'], true) ?: []) : [],
        ];
    }

    /**
     * GEO-Sichtbarkeit je Engine für einen Monat (für Report): wie viele Prompts,
     * wie oft erwähnt/zitiert.
     * @return array<string,array{prompts:int,mentioned:int,cited:int}>
     */
    public function geoSummary(int $clientId, string $period): array
    {
        [$start, $end] = $this->monthRange($period);
        $stmt = $this->db->prepare(
            "SELECT engine, COUNT(*) prompts, SUM(mentioned) mentioned, SUM(cited) cited
             FROM ai_mentions
             WHERE client_id = ? AND measured_at BETWEEN ? AND ?
               AND measured_at = (SELECT MAX(measured_at) FROM ai_mentions a2
                                  WHERE a2.client_id = ai_mentions.client_id
                                    AND a2.engine = ai_mentions.engine
                                    AND a2.measured_at BETWEEN ? AND ?)
             GROUP BY engine"
        );
        $stmt->execute([$clientId, $start, $end, $start, $end]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['engine']] = [
                'prompts'   => (int) $r['prompts'],
                'mentioned' => (int) $r['mentioned'],
                'cited'     => (int) $r['cited'],
            ];
        }
        return $out;
    }

    /**
     * Getestete GEO-Anfragen eines Kanals mit Ergebnis (erwähnt/zitiert), für die
     * Beispiel-Liste im Report. Zeigt bewusst BEIDES: einige erwähnte Anfragen (Stärken)
     * UND einige nicht-erwähnte (GEO-Lücken/Potenzial) — so wird sichtbar, wo die Marke
     * schon präsent ist und wo nicht. Nur Anfragen mit Prompt-Text (nicht bing_ai-Zeilen).
     *
     * @return array<int,array{prompt:string,mentioned:bool,cited:bool}>
     */
    public function geoPromptResults(int $clientId, string $engine, string $period, int $limit = 8): array
    {
        [$start, $end] = $this->monthRange($period);
        $stmt = $this->db->prepare(
            "SELECT p.prompt, a.mentioned, a.cited
             FROM ai_mentions a JOIN geo_prompts p ON p.id = a.prompt_id
             WHERE a.client_id = ? AND a.engine = ? AND a.measured_at BETWEEN ? AND ?
               AND a.measured_at = (SELECT MAX(measured_at) FROM ai_mentions a2
                                    WHERE a2.client_id = a.client_id AND a2.engine = a.engine
                                      AND a2.measured_at BETWEEN ? AND ?)
             ORDER BY a.cited DESC, a.mentioned DESC, p.id"
        );
        $stmt->execute([$clientId, $engine, $start, $end, $start, $end]);
        $rows = array_map(static fn($r) => [
            'prompt'    => (string) $r['prompt'],
            'mentioned' => (bool) $r['mentioned'],
            'cited'     => (bool) $r['cited'],
        ], $stmt->fetchAll());

        // Ausgewogen: bis zur Hälfte erwähnte (Stärken), Rest nicht-erwähnte (Lücken).
        $mentioned = array_values(array_filter($rows, static fn($r) => $r['mentioned']));
        $missing   = array_values(array_filter($rows, static fn($r) => !$r['mentioned']));
        $half = (int) ceil($limit / 2);
        $pick = array_merge(array_slice($mentioned, 0, $half), $missing);
        return array_slice($pick, 0, $limit);
    }

    /**
     * Konkrete GEO-Beispiele je Engine für den Report: pro Kanal eine Beispiel-Anfrage
     * mit Erwähnungs-/Zitierungs-Status, plus aggregierte Beispiele der in KI-Antworten
     * zitierten Quellen und genannten Wettbewerber (über alle Anfragen des Kanals).
     *
     * @return array<string,array{example_prompt:?string,example_mentioned:bool,
     *   example_cited:bool,citations:array<int,string>,competitors:array<int,string>}>
     */
    public function geoExamples(int $clientId, string $period): array
    {
        [$start, $end] = $this->monthRange($period);
        $stmt = $this->db->prepare(
            "SELECT a.engine, a.mentioned, a.cited, a.citations, a.competitors, p.prompt
             FROM ai_mentions a
             LEFT JOIN geo_prompts p ON p.id = a.prompt_id
             WHERE a.client_id = ? AND a.measured_at BETWEEN ? AND ?
               AND a.measured_at = (SELECT MAX(measured_at) FROM ai_mentions a2
                                    WHERE a2.client_id = a.client_id AND a2.engine = a.engine
                                      AND a2.measured_at BETWEEN ? AND ?)
             ORDER BY a.mentioned DESC, a.cited DESC"
        );
        $stmt->execute([$clientId, $start, $end, $start, $end]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $engine = (string) $r['engine'];
            if (!isset($out[$engine])) {
                $out[$engine] = [
                    'example_prompt'    => null,
                    'example_mentioned' => false,
                    'example_cited'     => false,
                    'citations'         => [],
                    'competitors'       => [],
                ];
            }
            // Erste (durch ORDER BY: erwähnte/zitierte zuerst) Zeile mit Prompt als Beispiel.
            if ($out[$engine]['example_prompt'] === null && !empty($r['prompt'])) {
                $out[$engine]['example_prompt']    = (string) $r['prompt'];
                $out[$engine]['example_mentioned'] = (bool) $r['mentioned'];
                $out[$engine]['example_cited']     = (bool) $r['cited'];
            }
            foreach ((json_decode((string) ($r['citations'] ?? '[]'), true) ?: []) as $c) {
                if (is_string($c) && $c !== '') {
                    $out[$engine]['citations'][] = $c;
                }
            }
            foreach ((json_decode((string) ($r['competitors'] ?? '[]'), true) ?: []) as $c) {
                if (is_string($c) && $c !== '') {
                    $out[$engine]['competitors'][] = $c;
                }
            }
        }
        // Deduplizieren + begrenzen.
        foreach ($out as $engine => &$e) {
            $e['citations']   = array_values(array_slice(array_unique($e['citations']), 0, 5));
            $e['competitors'] = array_values(array_slice(array_unique($e['competitors']), 0, 5));
        }
        return $out;
    }

    /**
     * Schreibt Ranking-Messwerte mit measured_at (Zeitreihe). Idempotent pro
     * (client, keyword, engine, source, Tag): vorhandene Zeilen desselben Tages
     * werden ersetzt, damit ein erneuter collect-Lauf keine Dubletten erzeugt.
     *
     * @param array<int,\Openstream\Visibility\Provider\Measurement> $measurements
     * @param string $measuredAt  Y-m-d
     * @return int Anzahl geschriebener Zeilen
     */
    public function saveMeasurements(int $clientId, array $measurements, string $measuredAt): int
    {
        $del = $this->db->prepare(
            'DELETE FROM measurements
             WHERE client_id=:cid AND engine=:engine AND source=:source
               AND measured_at=:mdate AND keyword_id <=> :kid'
        );
        $ins = $this->db->prepare(
            'INSERT INTO measurements
               (client_id, keyword_id, engine, position, url, impressions, clicks, ctr, source, measured_at)
             VALUES
               (:client_id, :keyword_id, :engine, :position, :url, :impressions, :clicks, :ctr, :source, :measured_at)'
        );

        $n = 0;
        foreach ($measurements as $m) {
            $del->execute([
                'cid' => $clientId, 'engine' => $m->engine, 'source' => $m->source,
                'mdate' => $measuredAt, 'kid' => $m->keywordId,
            ]);
            $ins->execute([
                'client_id'   => $clientId,
                'keyword_id'  => $m->keywordId,
                'engine'      => $m->engine,
                'position'    => $m->position,
                'url'         => $m->url,
                'impressions' => $m->impressions,
                'clicks'      => $m->clicks,
                'ctr'         => $m->ctr,
                'source'      => $m->source,
                'measured_at' => $measuredAt,
            ]);
            $n++;
        }
        return $n;
    }

    /** @return array<string,mixed>|null Kundenstammdaten */
    public function client(int $clientId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Ranking-Kennzahlen je Engine für einen Monat (letzter Messwert im Monat je Keyword),
     * plus die Ranking-Detailzeilen. Für den Report.
     *
     * @return array<string,array{count:int,avg_position:?float,impressions:int,clicks:int,rows:array<int,array<string,mixed>>}>
     *         keyed by engine (google|bing)
     */
    public function rankingSummary(int $clientId, string $period): array
    {
        // Datumsbereich statt DATE_FORMAT-String (vermeidet Collation-Konflikte, indexnutzbar).
        [$start, $end] = $this->monthRange($period);
        // Letzter Erhebungstag im Monat je (engine) — Momentaufnahme des Monats.
        // JOIN (nicht LEFT) auf approved=1: deaktivierte Keywords (z.B. Agentur-Begriffe nach
        // Positionierungs-Wechsel) fallen sofort aus dem Report, auch wenn noch alte
        // measurements existieren — ohne die historischen Messwerte zu löschen.
        $stmt = $this->db->prepare(
            "SELECT m.engine, m.source, m.position, m.impressions, m.clicks, k.keyword,
                    k.search_volume, k.difficulty, m.measured_at
             FROM measurements m
             JOIN keywords k ON k.id = m.keyword_id AND k.approved = 1
             WHERE m.client_id = :cid
               AND m.measured_at = (
                   SELECT MAX(measured_at) FROM measurements
                   WHERE client_id = :cid2 AND engine = m.engine
                     AND measured_at BETWEEN :start AND :end
               )
             ORDER BY m.engine, m.impressions DESC, m.position ASC"
        );
        $stmt->execute(['cid' => $clientId, 'cid2' => $clientId, 'start' => $start, 'end' => $end]);

        $byEngine = [];
        foreach ($stmt->fetchAll() as $r) {
            $e = $r['engine'];
            if (!isset($byEngine[$e])) {
                $byEngine[$e] = ['count' => 0, 'pos_sum' => 0.0, 'pos_n' => 0, 'impressions' => 0, 'clicks' => 0, 'rows' => []];
            }
            $byEngine[$e]['count']++;
            if ($r['position'] !== null) {
                $byEngine[$e]['pos_sum'] += (float) $r['position'];
                $byEngine[$e]['pos_n']++;
            }
            $byEngine[$e]['impressions'] += (int) ($r['impressions'] ?? 0);
            $byEngine[$e]['clicks'] += (int) ($r['clicks'] ?? 0);
            $byEngine[$e]['rows'][] = $r;
        }

        $out = [];
        foreach ($byEngine as $e => $d) {
            $out[$e] = [
                'count'        => $d['count'],
                'avg_position' => $d['pos_n'] > 0 ? round($d['pos_sum'] / $d['pos_n'], 1) : null,
                'impressions'  => $d['impressions'],
                'clicks'       => $d['clicks'],
                'rows'         => $d['rows'],
            ];
        }
        return $out;
    }

    /** Ø-Position je Engine für einen Monat (für Delta-Vergleich). @return array<string,?float> */
    public function avgPositionByEngine(int $clientId, string $period): array
    {
        [$start, $end] = $this->monthRange($period);
        $stmt = $this->db->prepare(
            "SELECT engine, ROUND(AVG(position),1) avg_pos
             FROM measurements
             WHERE client_id = ? AND position IS NOT NULL
               AND measured_at BETWEEN ? AND ?
             GROUP BY engine"
        );
        $stmt->execute([$clientId, $start, $end]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['engine']] = $r['avg_pos'] !== null ? (float) $r['avg_pos'] : null;
        }
        return $out;
    }

    /**
     * Speichert die monatliche Sichtbarkeits-Historie (idempotent pro Monat/Engine/Quelle).
     * @param array<int,array<string,mixed>> $rows aus HistoricalProvider::overview()
     * @return int Anzahl geschriebener Monate
     */
    public function saveVisibilityHistory(int $clientId, array $rows): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO visibility_history
               (client_id, engine, period, keywords_total, pos_1, pos_2_3, pos_4_10,
                pos_11_20, pos_21_50, pos_51_100, etv, is_new, is_lost, source)
             VALUES
               (:cid, :engine, :period, :kt, :p1, :p23, :p410, :p1120, :p2150, :p51100,
                :etv, :new, :lost, :source)
             ON DUPLICATE KEY UPDATE
               keywords_total=VALUES(keywords_total), pos_1=VALUES(pos_1),
               pos_2_3=VALUES(pos_2_3), pos_4_10=VALUES(pos_4_10), pos_11_20=VALUES(pos_11_20),
               pos_21_50=VALUES(pos_21_50), pos_51_100=VALUES(pos_51_100), etv=VALUES(etv),
               is_new=VALUES(is_new), is_lost=VALUES(is_lost)'
        );
        $n = 0;
        foreach ($rows as $r) {
            $stmt->execute([
                'cid' => $clientId, 'engine' => $r['engine'], 'period' => $r['period'],
                'kt' => $r['keywords_total'], 'p1' => $r['pos_1'], 'p23' => $r['pos_2_3'],
                'p410' => $r['pos_4_10'], 'p1120' => $r['pos_11_20'], 'p2150' => $r['pos_21_50'],
                'p51100' => $r['pos_51_100'], 'etv' => $r['etv'], 'new' => $r['is_new'],
                'lost' => $r['is_lost'], 'source' => 'dataforseo_historical',
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * Sichtbarkeits-Historie für den Report (chronologisch). Bis einschliesslich $period.
     * @return array<int,array<string,mixed>>
     */
    public function visibilityHistory(int $clientId, string $engine = 'google', ?string $upToPeriod = null): array
    {
        $sql = 'SELECT period, keywords_total, pos_1, pos_2_3, pos_4_10, pos_11_20,
                       pos_21_50, pos_51_100, etv, is_new, is_lost
                FROM visibility_history WHERE client_id = ? AND engine = ?';
        $params = [$clientId, $engine];
        if ($upToPeriod !== null) {
            $sql .= ' AND period <= ?';
            $params[] = $upToPeriod;
        }
        $sql .= ' ORDER BY period ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Speichert Onsite-Audit-Zeilen (je URL) für einen Tag (idempotent pro Tag/URL).
     * @param array<int,array<string,mixed>> $rows aus OnsiteProvider::audit()
     */
    public function saveOnsiteAudit(int $clientId, array $rows, string $measuredAt): int
    {
        $this->db->prepare('DELETE FROM onsite_audits WHERE client_id=? AND measured_at=? AND source=?')
            ->execute([$clientId, $measuredAt, 'dataforseo_onpage']);
        $ins = $this->db->prepare(
            'INSERT INTO onsite_audits (client_id, url, issues, source, measured_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $n = 0;
        foreach ($rows as $r) {
            $ins->execute([$clientId, $r['url'], $this->json($r), 'dataforseo_onpage', $measuredAt]);
            $n++;
        }
        return $n;
    }

    /**
     * Speichert PageSpeed/Lighthouse-Ergebnisse je URL (source=pagespeed). CWV in die
     * dedizierten Spalten, die vier Scores + URL im issues-JSON. Idempotent pro Tag.
     * @param array<int,array<string,mixed>> $rows aus PageSpeedProvider::analyze()
     */
    public function savePageSpeed(int $clientId, array $rows, string $measuredAt): int
    {
        $this->db->prepare('DELETE FROM onsite_audits WHERE client_id=? AND measured_at=? AND source=?')
            ->execute([$clientId, $measuredAt, 'pagespeed']);
        $ins = $this->db->prepare(
            'INSERT INTO onsite_audits (client_id, url, lcp_ms, inp_ms, cls, performance, issues, source, measured_at)
             VALUES (:cid, :url, :lcp, :inp, :cls, :perf, :issues, :src, :mdate)'
        );
        $n = 0;
        foreach ($rows as $r) {
            $ins->execute([
                'cid' => $clientId, 'url' => $r['url'] ?? null,
                'lcp' => $r['lcp_ms'] ?? null, 'inp' => $r['tbt_ms'] ?? null, 'cls' => $r['cls'] ?? null,
                'perf' => $r['performance'] ?? null,
                'issues' => $this->json([
                    'accessibility'  => $r['accessibility'] ?? null,
                    'best_practices' => $r['best_practices'] ?? null,
                    'seo'            => $r['seo'] ?? null,
                ]),
                'src' => 'pagespeed', 'mdate' => $measuredAt,
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * Speichert den Mozilla-Observatory-Sicherheitsscore (domain-weit, url=NULL,
     * source=observatory). Grade/Score/Testzahlen im issues-JSON. Idempotent pro Tag.
     * @param array{grade:?string,score:?int,tests_passed:?int,tests_failed:?int} $s
     */
    public function saveObservatory(int $clientId, array $s, string $measuredAt): void
    {
        $this->db->prepare('DELETE FROM onsite_audits WHERE client_id=? AND measured_at=? AND source=?')
            ->execute([$clientId, $measuredAt, 'observatory']);
        $this->db->prepare(
            'INSERT INTO onsite_audits (client_id, url, issues, source, measured_at)
             VALUES (:cid, NULL, :issues, :src, :mdate)'
        )->execute([
            'cid' => $clientId, 'issues' => $this->json($s), 'src' => 'observatory', 'mdate' => $measuredAt,
        ]);
    }

    /**
     * Mozilla-Observatory-Score für einen Monat (jüngster Stand). Für den Report.
     * @return array{grade:?string,score:?int,tests_passed:?int,tests_failed:?int}|null
     */
    public function observatory(int $clientId, string $period): ?array
    {
        [$start, $end] = $this->monthRange($period);
        $stmt = $this->db->prepare(
            "SELECT issues FROM onsite_audits
             WHERE client_id=? AND measured_at BETWEEN ? AND ? AND source='observatory'
             ORDER BY measured_at DESC LIMIT 1"
        );
        $stmt->execute([$clientId, $start, $end]);
        $r = $stmt->fetch();
        if (!$r || !$r['issues']) {
            return null;
        }
        $s = json_decode((string) $r['issues'], true) ?: [];
        return [
            'grade'        => $s['grade'] ?? null,
            'score'        => $s['score'] ?? null,
            'tests_passed' => $s['tests_passed'] ?? null,
            'tests_failed' => $s['tests_failed'] ?? null,
        ];
    }

    /**
     * PageSpeed/Lighthouse-Ergebnisse für einen Monat (jüngster Stand). Für den Report.
     * @return array<int,array<string,mixed>>
     */
    public function pageSpeed(int $clientId, string $period): array
    {
        [$start, $end] = $this->monthRange($period);
        $stmt = $this->db->prepare(
            "SELECT url, lcp_ms, inp_ms, cls, performance, issues FROM onsite_audits
             WHERE client_id=? AND measured_at BETWEEN ? AND ? AND source='pagespeed'
             AND measured_at=(SELECT MAX(measured_at) FROM onsite_audits o2
                              WHERE o2.client_id=onsite_audits.client_id AND o2.source='pagespeed'
                                AND o2.measured_at BETWEEN ? AND ?)"
        );
        $stmt->execute([$clientId, $start, $end, $start, $end]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $scores = $r['issues'] ? (json_decode((string) $r['issues'], true) ?: []) : [];
            $out[] = [
                'url'            => $r['url'],
                'performance'    => $r['performance'] !== null ? (int) $r['performance'] : null,
                'accessibility'  => $scores['accessibility'] ?? null,
                'best_practices' => $scores['best_practices'] ?? null,
                'seo'            => $scores['seo'] ?? null,
                'lcp_ms'         => $r['lcp_ms'] !== null ? (int) $r['lcp_ms'] : null,
                'tbt_ms'         => $r['inp_ms'] !== null ? (int) $r['inp_ms'] : null,
                'cls'            => $r['cls'] !== null ? (float) $r['cls'] : null,
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> Onsite-Audit für einen Monat */
    public function onsiteAudit(int $clientId, string $period): array
    {
        [$start, $end] = $this->monthRange($period);
        // Subquery muss auf source='dataforseo_onpage' filtern, sonst wählt ein späterer
        // PageSpeed-Lauf (andere source, gleiche Tabelle) den MAX-Tag und die Zeilen fehlen.
        $stmt = $this->db->prepare(
            "SELECT url, issues FROM onsite_audits
             WHERE client_id=? AND measured_at BETWEEN ? AND ? AND source='dataforseo_onpage'
             AND measured_at=(SELECT MAX(measured_at) FROM onsite_audits o2
                              WHERE o2.client_id=onsite_audits.client_id AND o2.source='dataforseo_onpage'
                                AND o2.measured_at BETWEEN ? AND ?)"
        );
        $stmt->execute([$clientId, $start, $end, $start, $end]);
        return array_map(static fn($r) => json_decode((string) $r['issues'], true) ?: ['url' => $r['url']], $stmt->fetchAll());
    }

    /**
     * Speichert einen Backlink-Snapshot (idempotent pro Tag).
     * @param array<string,mixed> $s aus OffsiteProvider::summary()
     */
    public function saveBacklinks(int $clientId, array $s, string $measuredAt): void
    {
        $this->db->prepare('DELETE FROM backlinks WHERE client_id=? AND measured_at=?')
            ->execute([$clientId, $measuredAt]);
        $this->db->prepare(
            'INSERT INTO backlinks (client_id, referring_domains, backlinks_total, domain_rank,
                new_last_period, lost_last_period, top_referring_domains, source, measured_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $clientId, $s['referring_domains'], $s['backlinks'], $s['domain_rank'],
            $s['new'], $s['lost'],
            isset($s['top_domains']) ? $this->json($s['top_domains']) : null,
            'dataforseo_backlinks', $measuredAt,
        ]);
    }

    /** @return array<string,mixed>|null jüngster Backlink-Snapshot im Monat */
    public function backlinks(int $clientId, string $period): ?array
    {
        [$start, $end] = $this->monthRange($period);
        $stmt = $this->db->prepare(
            'SELECT referring_domains, backlinks_total, domain_rank, new_last_period, lost_last_period,
                    top_referring_domains
             FROM backlinks WHERE client_id=? AND measured_at BETWEEN ? AND ?
             ORDER BY measured_at DESC LIMIT 1'
        );
        $stmt->execute([$clientId, $start, $end]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['top_referring_domains'] = $row['top_referring_domains']
            ? (json_decode((string) $row['top_referring_domains'], true) ?: [])
            : [];
        return $row;
    }

    /**
     * Speichert Social-Media-Messwerte (idempotent pro Tag/Plattform/Account).
     * @param array<int,\Openstream\Visibility\Provider\SocialMetric> $metrics
     * @return int Anzahl geschriebener Zeilen
     */
    public function saveSocialMetrics(int $clientId, array $metrics, string $measuredAt): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO social_metrics
               (client_id, platform, account, followers, views_total, monthly_views, posts_total, source, measured_at)
             VALUES (:cid, :platform, :account, :followers, :views, :mviews, :posts, :source, :mdate)
             ON DUPLICATE KEY UPDATE
               -- NULL-Werte überschreiben bestehende NICHT (COALESCE): so ergänzen sich
               -- Data-API-Zeile (Follower/Lifetime-Views) und OAuth-Zeile (echte Monats-
               -- Views) desselben Kanals zu EINER Zeile statt sich gegenseitig zu leeren.
               followers=COALESCE(VALUES(followers), followers),
               views_total=COALESCE(VALUES(views_total), views_total),
               monthly_views=COALESCE(VALUES(monthly_views), monthly_views),
               posts_total=COALESCE(VALUES(posts_total), posts_total),
               source=VALUES(source)'
        );
        $n = 0;
        foreach ($metrics as $m) {
            $stmt->execute([
                'cid' => $clientId, 'platform' => $m->platform, 'account' => $m->account,
                'followers' => $m->followers, 'views' => $m->viewsTotal, 'mviews' => $m->monthlyViews,
                'posts' => $m->postsTotal, 'source' => $m->source, 'mdate' => $measuredAt,
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * Social-Kennzahlen je Kanal für einen Monat (für den Report). Monats-Views:
     *  - falls echte `monthly_views` vorliegen (OAuth/Analytics): diese direkt nutzen.
     *  - sonst: jüngster views_total-Stand im Monat minus jüngster Stand im Vormonat
     *    (Lifetime-Delta, geclampt auf ≥0 gegen rückwirkende Zähler-Korrekturen).
     *
     * @return array<int,array{platform:string,account:string,followers:?int,views_total:?int,
     *         monthly_views:?int,posts_total:?int,source:string}>
     */
    public function socialMonthly(int $clientId, string $period): array
    {
        [$start, $end] = $this->monthRange($period);
        $prevPeriod = date('Y-m', strtotime($start . ' -1 month'));
        [$pStart, $pEnd] = $this->monthRange($prevPeriod);

        // Jüngster Stand je (platform, account) im Zielmonat.
        $latest = $this->latestSocialInRange($clientId, $start, $end);
        // Jüngster Stand je (platform, account) im Vormonat (für das Delta-Fallback).
        $prev = $this->latestSocialInRange($clientId, $pStart, $pEnd);

        $out = [];
        foreach ($latest as $key => $row) {
            if ($row['monthly_views'] !== null) {
                $mv = (int) $row['monthly_views']; // echter Monatswert (OAuth)
            } elseif ($row['views_total'] !== null && isset($prev[$key]) && $prev[$key]['views_total'] !== null) {
                $mv = max(0, (int) $row['views_total'] - (int) $prev[$key]['views_total']); // Delta-Fallback
            } else {
                $mv = null;
            }
            $out[] = [
                'platform'      => $row['platform'],
                'account'       => $row['account'],
                'followers'     => $row['followers'] !== null ? (int) $row['followers'] : null,
                'views_total'   => $row['views_total'] !== null ? (int) $row['views_total'] : null,
                'monthly_views' => $mv,
                'posts_total'   => $row['posts_total'] !== null ? (int) $row['posts_total'] : null,
                'source'        => (string) ($row['source'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Jüngster social_metrics-Stand je (platform, account) in einem Datumsbereich.
     * @return array<string,array<string,mixed>> key = "platform|account"
     */
    private function latestSocialInRange(int $clientId, string $start, string $end): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.platform, s.account, s.followers, s.views_total, s.monthly_views,
                    s.posts_total, s.source, s.measured_at
             FROM social_metrics s
             WHERE s.client_id = :cid AND s.measured_at BETWEEN :start AND :end
               AND s.measured_at = (
                   SELECT MAX(measured_at) FROM social_metrics
                   WHERE client_id = :cid2 AND platform = s.platform AND account = s.account
                     AND measured_at BETWEEN :start2 AND :end2
               )'
        );
        $stmt->execute([
            'cid' => $clientId, 'cid2' => $clientId,
            'start' => $start, 'end' => $end, 'start2' => $start, 'end2' => $end,
        ]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['platform'] . '|' . $r['account']] = $r;
        }
        return $out;
    }

    /**
     * Speichert den OVS eines Monats (idempotent pro Monat) samt Zusammensetzung.
     * @param array<string,int> $components
     */
    public function saveVisibilityScore(int $clientId, string $period, int $score, array $components): void
    {
        $this->db->prepare(
            'INSERT INTO visibility_score (client_id, period, score, components)
             VALUES (:cid, :period, :score, :components)
             ON DUPLICATE KEY UPDATE score=VALUES(score), components=VALUES(components)'
        )->execute([
            'cid' => $clientId, 'period' => $period, 'score' => $score,
            'components' => $this->json($components),
        ]);
    }

    /**
     * OVS-Zeitreihe (chronologisch, bis einschliesslich $period) für den Trend-Chart.
     * @return array<int,array{period:string,score:int}>
     */
    public function visibilityScoreHistory(int $clientId, string $period): array
    {
        $stmt = $this->db->prepare(
            'SELECT period, score FROM visibility_score
             WHERE client_id = ? AND period <= ? ORDER BY period ASC'
        );
        $stmt->execute([$clientId, $period]);
        return array_map(
            static fn($r) => ['period' => (string) $r['period'], 'score' => (int) $r['score']],
            $stmt->fetchAll()
        );
    }

    /**
     * Speichert Newsletter-Kampagnen-Kennzahlen (idempotent pro Kampagne).
     * @param array<int,\Openstream\Visibility\Provider\NewsletterStat> $stats
     * @return int Anzahl geschriebener Zeilen
     */
    public function saveNewsletterStats(int $clientId, array $stats, string $measuredAt): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO newsletter_stats
               (client_id, campaign_ref, subject, sent_at, recipients, opens, clicks,
                bounces, unsubscribes, list_size, provider, measured_at)
             VALUES (:cid, :ref, :subject, :sent, :rec, :opens, :clicks, :bounces, :unsub,
                     :list, :provider, :mdate)
             ON DUPLICATE KEY UPDATE
               subject=VALUES(subject), sent_at=VALUES(sent_at), recipients=VALUES(recipients),
               opens=VALUES(opens), clicks=VALUES(clicks), bounces=VALUES(bounces),
               unsubscribes=VALUES(unsubscribes), list_size=VALUES(list_size), measured_at=VALUES(measured_at)'
        );
        $n = 0;
        foreach ($stats as $s) {
            $stmt->execute([
                'cid' => $clientId, 'ref' => $s->campaignRef, 'subject' => $s->subject,
                'sent' => $s->sentAt, 'rec' => $s->recipients, 'opens' => $s->opens,
                'clicks' => $s->clicks, 'bounces' => $s->bounces, 'unsub' => $s->unsubscribes,
                'list' => $s->listSize, 'provider' => $s->provider, 'mdate' => $measuredAt,
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * Newsletter-Kampagnen für den Report (bis einschliesslich Berichtsmonat, jüngste zuerst).
     * @return array<int,array<string,mixed>>
     */
    public function newsletterCampaigns(int $clientId, string $period, int $limit = 6): array
    {
        [, $end] = $this->monthRange($period);
        // Nur echte Versände: reine Listen-Snapshots (campaign_ref 'list-…') und
        // Test-/Teilversände mit sehr wenigen Empfängern (< 10) ausschliessen — sonst
        // erscheinen absurde Raten (>100 %) und leere Zeilen im Report.
        $stmt = $this->db->prepare(
            "SELECT campaign_ref, subject, sent_at, recipients, opens, clicks, bounces,
                    unsubscribes, list_size, provider
             FROM newsletter_stats
             WHERE client_id = ? AND sent_at IS NOT NULL AND sent_at <= ?
               AND campaign_ref NOT LIKE 'list-%'
               AND (recipients IS NULL OR recipients >= 10)
             ORDER BY sent_at DESC, id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$clientId, $end]);
        return $stmt->fetchAll();
    }

    /**
     * Aktive OAuth-Verbindungen eines Kunden (für collect). @return array<int,array<string,mixed>>
     */
    public function socialConnections(int $clientId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, client_id, platform, account_ref, account_label, refresh_token_enc, scopes
             FROM social_connections WHERE client_id = ? AND status = 'active'"
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll();
    }

    /**
     * Speichert/aktualisiert eine OAuth-Verbindung (Refresh-Token bereits verschlüsselt).
     */
    public function saveSocialConnection(
        int $clientId,
        string $platform,
        ?string $accountRef,
        ?string $accountLabel,
        string $refreshTokenEnc,
        ?string $scopes,
    ): void {
        $this->db->prepare(
            "INSERT INTO social_connections
               (client_id, platform, account_ref, account_label, refresh_token_enc, scopes, status)
             VALUES (:cid, :platform, :ref, :label, :tok, :scopes, 'active')
             ON DUPLICATE KEY UPDATE
               account_label=VALUES(account_label), refresh_token_enc=VALUES(refresh_token_enc),
               scopes=VALUES(scopes), status='active'"
        )->execute([
            'cid' => $clientId, 'platform' => $platform, 'ref' => $accountRef,
            'label' => $accountLabel, 'tok' => $refreshTokenEnc, 'scopes' => $scopes,
        ]);
    }

    /**
     * Aktualisiert den (verschlüsselten) Token einer bestehenden Verbindung und markiert sie
     * als zuletzt genutzt. Für Meta/Instagram, wo das rollierende Long-Lived-Token bei jedem
     * Lauf erneuert und zurückgeschrieben wird.
     */
    public function updateSocialConnectionToken(int $connectionId, string $refreshTokenEnc): void
    {
        $this->db->prepare(
            'UPDATE social_connections
             SET refresh_token_enc = :tok, last_used_at = NOW()
             WHERE id = :id'
        )->execute(['tok' => $refreshTokenEnc, 'id' => $connectionId]);
    }

    /** Erzeugt und speichert ein OAuth-State-Token (CSRF-Schutz). */
    public function createOAuthState(string $state, int $clientId, string $platform): void
    {
        $this->db->prepare(
            'INSERT INTO oauth_states (state, client_id, platform) VALUES (?, ?, ?)'
        )->execute([$state, $clientId, $platform]);
    }

    /**
     * Verbraucht ein State-Token (einmalig): gibt client_id+platform zurück und löscht es.
     * @return array{client_id:int,platform:string}|null
     */
    public function consumeOAuthState(string $state): ?array
    {
        $stmt = $this->db->prepare('SELECT client_id, platform FROM oauth_states WHERE state = ?');
        $stmt->execute([$state]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $this->db->prepare('DELETE FROM oauth_states WHERE state = ?')->execute([$state]);
        return ['client_id' => (int) $row['client_id'], 'platform' => (string) $row['platform']];
    }

    /** Erster/letzter Tag eines Monats (YYYY-MM). @return array{0:string,1:string} */
    private function monthRange(string $period): array
    {
        $start = $period . '-01';
        $end = date('Y-m-t', strtotime($start));
        return [$start, $end];
    }

    /** @param array<mixed> $v */
    private function json(array $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,true> lowercase-Set bestehender Werte */
    private function existingSet(string $sql, int $clientId): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$clientId]);
        $set = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $set[mb_strtolower((string) $v)] = true;
        }
        return $set;
    }
}
