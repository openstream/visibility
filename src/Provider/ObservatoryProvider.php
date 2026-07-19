<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;

/**
 * Mozilla HTTP Observatory (MDN, v2): Sicherheits-Header-Bewertung der Website.
 * Prüft HTTPS-Sicherheitsheader (Content-Security-Policy, HSTS, X-Content-Type-Options,
 * X-Frame-Options, Referrer-Policy u.a.) und vergibt Note (A+ bis F) + Score (0-100+).
 *
 * Gratis, KEIN API-Key. POST startet den Scan und liefert direkt Grade/Score/Testzahlen.
 * Ein Scan ist max. alle 3 Minuten je Host möglich; Ergebnisse werden 24 h gecacht.
 */
final class ObservatoryProvider
{
    private const ENDPOINT = 'https://observatory-api.mdn.mozilla.net/api/v2/scan';

    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 60]);
    }

    /**
     * Scannt eine Domain (Host ohne Schema). @param string $domain z.B. openstream.ch
     * @return array{grade:?string,score:?int,tests_passed:?int,tests_failed:?int}|null
     */
    public function scan(string $domain): ?array
    {
        $host = preg_replace('#^https?://#', '', rtrim($domain, '/'));
        $host = preg_replace('#^www\.#', '', (string) $host);
        try {
            $res = $this->http->post(self::ENDPOINT, ['query' => ['host' => $host]]);
        } catch (\Throwable $e) {
            return null;
        }
        $data = json_decode((string) $res->getBody(), true);
        if (!is_array($data) || !isset($data['grade'])) {
            return null;
        }
        return [
            'grade'         => isset($data['grade']) ? (string) $data['grade'] : null,
            'score'         => isset($data['score']) ? (int) $data['score'] : null,
            'tests_passed'  => isset($data['tests_passed']) ? (int) $data['tests_passed'] : null,
            'tests_failed'  => isset($data['tests_failed']) ? (int) $data['tests_failed'] : null,
        ];
    }
}
