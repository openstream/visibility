<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;

/**
 * Newsletter-Kennzahlen via Mailchimp Marketing API. Der API-Key enthält den Server-Prefix
 * nach dem Bindestrich (z.B. `...-us21`) → daraus wird die Datacenter-Basis-URL abgeleitet.
 * Auth: HTTP Basic (beliebiger User + API-Key als Passwort).
 *
 * Endpunkte: `/campaigns` (Liste + report_summary), `/lists` (Listengrösse).
 */
final class MailchimpProvider implements NewsletterProvider
{
    private Client $http;

    public function __construct(string $apiKey, ?Client $http = null)
    {
        $dc = self::datacenter($apiKey);
        $this->http = $http ?? new Client([
            'base_uri' => "https://{$dc}.api.mailchimp.com/3.0/",
            'timeout'  => 30,
            'auth'     => ['anystring', $apiKey],
        ]);
    }

    public function name(): string
    {
        return 'mailchimp';
    }

    public function recentCampaigns(int $limit = 12): array
    {
        $res = $this->http->get('campaigns', ['query' => [
            'count'  => $limit,
            'sort_field' => 'send_time',
            'sort_dir'   => 'DESC',
            'status'     => 'sent',
        ]]);
        $data = json_decode((string) $res->getBody(), true);
        return self::parseCampaigns($data);
    }

    /**
     * Extrahiert den Datacenter-Prefix aus dem API-Key (Teil nach dem letzten Bindestrich).
     */
    public static function datacenter(string $apiKey): string
    {
        $pos = strrpos($apiKey, '-');
        if ($pos === false) {
            throw new \RuntimeException('Mailchimp-API-Key ohne Datacenter-Suffix (…-usXX erwartet).');
        }
        return substr($apiKey, $pos + 1);
    }

    /**
     * Wandelt die Mailchimp-`/campaigns`-Antwort in NewsletterStat[] um. Rein (testbar).
     * @param array<string,mixed> $data
     * @return array<int,NewsletterStat>
     */
    public static function parseCampaigns(array $data): array
    {
        $out = [];
        foreach ($data['campaigns'] ?? [] as $c) {
            $r = $c['report_summary'] ?? [];
            $recipients = $c['emails_sent'] ?? null;
            $out[] = new NewsletterStat(
                campaignRef:  (string) ($c['id'] ?? ''),
                subject:      $c['settings']['subject_line'] ?? null,
                sentAt:       isset($c['send_time']) && $c['send_time'] ? substr((string) $c['send_time'], 0, 10) : null,
                recipients:   $recipients !== null ? (int) $recipients : null,
                opens:        isset($r['unique_opens']) ? (int) $r['unique_opens'] : null,
                clicks:       isset($r['subscriber_clicks']) ? (int) $r['subscriber_clicks'] : null,
                bounces:      null, // in report_summary nicht enthalten; optional via /reports später
                unsubscribes: null,
                listSize:     isset($c['recipients']['recipient_count']) ? (int) $c['recipients']['recipient_count'] : null,
                provider:     'mailchimp',
            );
        }
        return $out;
    }
}
