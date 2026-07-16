<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;

/**
 * Newsletter-Kennzahlen via Sendy (selbst-gehostet). Sendys offizielle API ist begrenzt:
 * `/api/subscribers/active-subscriber-count.php` liefert die aktive Listengrösse (für das
 * Wachstum über Zeit). Kampagnen-Opens/Clicks je Versand hat die Standard-API NICHT — die
 * liegen in Sendys MySQL-DB. Deshalb liefert dieser Provider standardmässig nur die
 * Listengrösse; Kampagnen-Detailstats folgen über einen optionalen read-only DB-Pfad
 * (beim Bau je nach Sendy-Version/Zugang zu prüfen, s. README).
 *
 * .env je Kunde: SENDY_API_KEY_<SLUG>, SENDY_URL_<SLUG>. Config: newsletter.list_id.
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
        // Sendy-API kennt keine Kampagnen-Report-Liste. Wir erfassen die aktuelle
        // Listengrösse als eigenen „Snapshot" (campaignRef = Datum), damit das
        // Listen-Wachstum als Zeitreihe entsteht. Opens/Clicks: null (nicht via API).
        $listSize = $this->activeSubscriberCount();
        if ($listSize === null) {
            return [];
        }
        return [new NewsletterStat(
            campaignRef:  'list-' . date('Y-m-d'),
            subject:      null,
            sentAt:       date('Y-m-d'),
            recipients:   null,
            opens:        null,
            clicks:       null,
            bounces:      null,
            unsubscribes: null,
            listSize:     $listSize,
            provider:     'sendy',
        )];
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
        // Sendy gibt bei Erfolg eine reine Zahl, sonst eine Fehlermeldung.
        return ctype_digit($body) ? (int) $body : null;
    }
}
