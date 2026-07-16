<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;

/**
 * Newsletter-Kennzahlen via Sendy (selbst-gehostet). Sendys Standard-API liefert nur
 * Abonnenten-Funktionen (keine Kampagnen-Reports). Deshalb nutzt dieser Provider eine
 * eigene, in die Sendy-Installation gelegte Stats-Extension (`/api/custom/…`), die die
 * Report-Daten aus Sendys DB als JSON ausliefert (localhost-DB-Zugriff, per API-Key geschützt):
 *
 *   - `campaigns-list.php`   → letzte Kampagnen (id, label, sent_iso, recipients)
 *   - `campaign-summary.php` → sent, unique_opens, clicks, unsubscribes je Kampagne
 *
 * Zusätzlich liefert die offizielle `active-subscriber-count.php` die aktuelle
 * Listengrösse (Listen-Wachstum). .env je Kunde: SENDY_API_KEY_<SLUG>, SENDY_URL_<SLUG>.
 * Config: newsletter.list_id (für die Listengrösse).
 */
final class SendyProvider implements NewsletterProvider
{
    private Client $http;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl,
        private readonly ?string $listId = null,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['base_uri' => rtrim($baseUrl, '/') . '/', 'timeout' => 30]);
    }

    public function name(): string
    {
        return 'sendy';
    }

    public function recentCampaigns(int $limit = 12): array
    {
        $list = $this->fetchJson('api/custom/campaigns-list.php', ['limit' => $limit]);
        $campaigns = is_array($list['campaigns'] ?? null) ? $list['campaigns'] : [];
        if (!$campaigns) {
            // Extension nicht verfügbar → wenigstens die Listengrösse als Snapshot.
            return $this->listSnapshot();
        }

        // Listengrösse einmal holen, an die jüngste Kampagne hängen (Wachstums-Kennzahl).
        $listSize = $this->activeSubscriberCount();

        $out = [];
        foreach ($campaigns as $i => $c) {
            $id = $c['campaign_id'] ?? null;
            if ($id === null) {
                continue;
            }
            $summary = $this->fetchJson('api/custom/campaign-summary.php', ['campaign_id' => (int) $id]);
            $out[] = self::toStat($c, $summary, $i === 0 ? $listSize : null);
        }
        return $out;
    }

    /**
     * Wandelt eine Kampagnen-Listenzeile + deren Summary in einen NewsletterStat. Rein (testbar).
     * @param array<string,mixed> $listRow  aus campaigns-list.php
     * @param array<string,mixed> $summary  aus campaign-summary.php (kann leer sein)
     */
    public static function toStat(array $listRow, array $summary, ?int $listSize): NewsletterStat
    {
        // sentAt: bevorzugt ISO aus der Liste (Datumsteil).
        $sentAt = null;
        if (!empty($listRow['sent_iso'])) {
            $sentAt = substr((string) $listRow['sent_iso'], 0, 10);
        } elseif (!empty($listRow['sent'])) {
            $sentAt = date('Y-m-d', (int) $listRow['sent']);
        }
        $recipients = $summary['sent'] ?? $listRow['recipients'] ?? $listRow['to_send'] ?? null;

        return new NewsletterStat(
            campaignRef:  (string) ($listRow['campaign_id'] ?? ''),
            subject:      self::cleanLabel($listRow['label'] ?? ($listRow['title'] ?? null)),
            sentAt:       $sentAt,
            recipients:   $recipients !== null ? (int) $recipients : null,
            opens:        isset($summary['unique_opens']) ? (int) $summary['unique_opens'] : null,
            clicks:       isset($summary['clicks']) ? (int) $summary['clicks'] : null,
            bounces:      isset($summary['bounces']) ? (int) $summary['bounces'] : null,
            unsubscribes: isset($summary['unsubscribes']) ? (int) $summary['unsubscribes'] : null,
            listSize:     $listSize,
            provider:     'sendy',
        );
    }

    /**
     * Bereinigt das Sendy-Label für den Report: schneidet den intern angehängten
     * Erstell-Timestamp (" — 2026-07-02 15:09") ab, der Kunde sieht nur den Titel.
     */
    public static function cleanLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }
        // " — YYYY-MM-DD HH:MM" am Ende entfernen (Em-Dash oder normaler Bindestrich).
        $clean = preg_replace('/\s*[—-]\s*\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}\s*$/u', '', $label);
        return trim((string) $clean);
    }

    /** Aktive Abonnenten der konfigurierten Liste. Null, wenn nicht ermittelbar. */
    public function activeSubscriberCount(): ?int
    {
        if (!$this->listId) {
            return null;
        }
        try {
            $res = $this->http->post('api/subscribers/active-subscriber-count.php', ['form_params' => [
                'api_key' => $this->apiKey,
                'list_id' => $this->listId,
            ]]);
        } catch (\Throwable) {
            return null;
        }
        $body = trim((string) $res->getBody());
        return ctype_digit($body) ? (int) $body : null;
    }

    /** Fallback ohne Extension: nur die aktuelle Listengrösse als Snapshot. @return array<int,NewsletterStat> */
    private function listSnapshot(): array
    {
        $listSize = $this->activeSubscriberCount();
        if ($listSize === null) {
            return [];
        }
        return [new NewsletterStat(
            campaignRef: 'list-' . date('Y-m-d'), subject: null, sentAt: date('Y-m-d'),
            recipients: null, opens: null, clicks: null, bounces: null, unsubscribes: null,
            listSize: $listSize, provider: 'sendy',
        )];
    }

    /**
     * POST an einen Extension-Endpunkt, gibt das dekodierte JSON zurück (leeres Array bei Fehler).
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function fetchJson(string $path, array $fields): array
    {
        try {
            $res = $this->http->post($path, ['form_params' => array_merge(['api_key' => $this->apiKey], $fields)]);
        } catch (\Throwable) {
            return [];
        }
        $data = json_decode((string) $res->getBody(), true);
        return is_array($data) ? $data : [];
    }
}
