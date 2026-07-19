<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

use GuzzleHttp\Client;
use Openstream\Visibility\App;

/**
 * Google PageSpeed Insights (Lighthouse, Lab-Daten) für eine URL. Liefert die vier
 * Lighthouse-Scores (Performance, Accessibility/Barrierefreiheit, Best Practices, SEO,
 * je 0-100) plus die Kern-Ladezeit-Metriken (LCP, TBT als INP-Näherung, CLS).
 *
 * Gratis-API, ein Key (GOOGLE_API_KEY) deckt auch CrUX ab. Strategy=mobile (Google
 * bewertet mobil-first). Kein eigener Crawler — offizielle Google-API.
 */
final class PageSpeedProvider
{
    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    private const CATEGORIES = ['performance', 'accessibility', 'best-practices', 'seo'];

    private Client $http;

    public function __construct(private readonly ?string $apiKey = null, ?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 60]);
    }

    public static function fromEnv(): self
    {
        $key = App::get()->env('GOOGLE_API_KEY');
        if (!$key) {
            throw new \RuntimeException('GOOGLE_API_KEY fehlt in .env (PageSpeed/CrUX).');
        }
        return new self($key);
    }

    /**
     * Analysiert eine URL. @return array{
     *   url:string, performance:?int, accessibility:?int, best_practices:?int, seo:?int,
     *   lcp_ms:?int, tbt_ms:?int, cls:?float
     * }|null
     */
    public function analyze(string $url): ?array
    {
        // Die PageSpeed-API erwartet WIEDERHOLTE category-Parameter (category=a&category=b),
        // NICHT die von http_build_query erzeugte Index-Form (category[0]=a). Daher die
        // Query-String von Hand bauen, sonst wird nur die Default-Kategorie (performance) geliefert.
        $parts = ['url=' . rawurlencode($url), 'strategy=mobile'];
        foreach (self::CATEGORIES as $c) {
            $parts[] = 'category=' . rawurlencode($c);
        }
        if ($this->apiKey) {
            $parts[] = 'key=' . rawurlencode($this->apiKey);
        }

        try {
            $res = $this->http->get(self::ENDPOINT . '?' . implode('&', $parts));
        } catch (\Throwable $e) {
            return null;
        }
        $data = json_decode((string) $res->getBody(), true);
        $lh = $data['lighthouseResult'] ?? null;
        if ($lh === null) {
            return null;
        }

        $cats = $lh['categories'] ?? [];
        $audits = $lh['audits'] ?? [];

        return [
            'url'            => $url,
            'performance'    => self::score($cats, 'performance'),
            'accessibility'  => self::score($cats, 'accessibility'),
            'best_practices' => self::score($cats, 'best-practices'),
            'seo'            => self::score($cats, 'seo'),
            'lcp_ms'         => self::metricMs($audits, 'largest-contentful-paint'),
            'tbt_ms'         => self::metricMs($audits, 'total-blocking-time'),
            'cls'            => self::metricNum($audits, 'cumulative-layout-shift'),
        ];
    }

    /** Score 0-100 aus einer Lighthouse-Kategorie (0.0-1.0 → 0-100). */
    private static function score(array $cats, string $key): ?int
    {
        $s = $cats[$key]['score'] ?? null;
        return is_numeric($s) ? (int) round((float) $s * 100) : null;
    }

    /** Numerischer Wert eines Audits in Millisekunden. */
    private static function metricMs(array $audits, string $key): ?int
    {
        $v = $audits[$key]['numericValue'] ?? null;
        return is_numeric($v) ? (int) round((float) $v) : null;
    }

    private static function metricNum(array $audits, string $key): ?float
    {
        $v = $audits[$key]['numericValue'] ?? null;
        return is_numeric($v) ? round((float) $v, 3) : null;
    }
}
