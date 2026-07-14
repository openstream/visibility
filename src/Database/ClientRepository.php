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
