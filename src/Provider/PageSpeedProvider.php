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

    /**
     * Deutsche, kundenverständliche Bezeichnungen für die häufigsten Lighthouse-Audits.
     * Unbekannte Audits fallen auf den englischen Titel zurück (s. auditLabel()).
     */
    private const AUDIT_DE = [
        // Performance
        'largest-contentful-paint'      => 'Grösster Inhalt lädt zu langsam',
        'first-contentful-paint'        => 'Erster Inhalt erscheint spät',
        'speed-index'                   => 'Seite baut sich langsam auf',
        'total-blocking-time'           => 'Seite ist verzögert bedienbar',
        'cumulative-layout-shift'       => 'Layout verschiebt sich beim Laden',
        'server-response-time'          => 'Server antwortet langsam',
        'render-blocking-resources'     => 'Render-blockierende Ressourcen (CSS/JS)',
        'unused-css-rules'              => 'Ungenutztes CSS',
        'unused-javascript'             => 'Ungenutztes JavaScript',
        'uses-optimized-images'         => 'Bilder nicht optimal komprimiert',
        'modern-image-formats'          => 'Moderne Bildformate (WebP/AVIF) fehlen',
        'uses-responsive-images'        => 'Bilder nicht für die Anzeigegrösse skaliert',
        'efficient-animated-content'    => 'Animierte Inhalte ineffizient',
        'uses-text-compression'         => 'Textkompression fehlt',
        'uses-long-cache-ttl'           => 'Kurze Cache-Zeiten (Caching optimierbar)',
        'font-display'                  => 'Schriften blockieren die Anzeige',
        'legacy-javascript'            => 'Veraltetes JavaScript ausgeliefert',
        'mainthread-work-breakdown'     => 'Zu viel Arbeit im Haupt-Thread',
        'bootup-time'                   => 'JavaScript-Ausführung dauert lange',
        'network-requests'              => 'Viele Netzwerk-Anfragen',
        'dom-size'                      => 'Sehr grosse Seitenstruktur (DOM)',
        'interactive'                   => 'Seite spät vollständig bedienbar',
        'redirects'                     => 'Mehrfache Weiterleitungen vermeiden',
        'max-potential-fid'             => 'Erste Interaktion kann verzögert reagieren',
        // Neue Lighthouse-«insight»-Audit-IDs (ersetzen ältere Namen)
        'cache-insight'                 => 'Kurze Cache-Zeiten (Caching optimierbar)',
        'document-latency-insight'      => 'Server antwortet langsam (Dokument-Latenz)',
        'font-display-insight'          => 'Schriften blockieren die Anzeige',
        'image-delivery-insight'        => 'Bilder nicht optimal ausgeliefert (Grösse/Format)',
        'network-dependency-tree-insight' => 'Lange Ladeketten (verschachtelte Ressourcen)',
        'lcp-discovery-insight'         => 'Grösster Inhalt wird spät entdeckt',
        'render-blocking-insight'       => 'Render-blockierende Ressourcen (CSS/JS)',
        'forced-reflow-insight'         => 'Erzwungene Layout-Neuberechnung (JS)',
        'legacy-javascript-insight'     => 'Veraltetes JavaScript ausgeliefert',
        'third-parties-insight'         => 'Drittanbieter-Skripte bremsen die Seite',
        'duplicated-javascript-insight' => 'Doppelt geladenes JavaScript',
        'viewport-insight'              => 'Viewport für Mobilgeräte optimierbar',
        // Accessibility (Barrierefreiheit)
        'image-alt'                     => 'Bilder ohne Alt-Text',
        'color-contrast'                => 'Zu geringer Farbkontrast',
        'link-name'                     => 'Links ohne erkennbaren Namen',
        'button-name'                   => 'Schaltflächen ohne Beschriftung',
        'label'                         => 'Formularfelder ohne Beschriftung',
        'heading-order'                 => 'Überschriften nicht in logischer Reihenfolge',
        'html-has-lang'                 => 'Sprache der Seite nicht angegeben',
        'meta-viewport'                 => 'Zoom für Nutzer eingeschränkt',
        'aria-required-attr'            => 'Fehlende ARIA-Attribute',
        'document-title'               => 'Seitentitel fehlt',
        // Best Practices
        'is-on-https'                   => 'Nicht durchgängig HTTPS',
        'uses-http2'                    => 'HTTP/2 nicht genutzt',
        'errors-in-console'             => 'JavaScript-Fehler in der Konsole',
        'image-aspect-ratio'            => 'Bilder verzerrt (Seitenverhältnis)',
        'deprecations'                  => 'Veraltete Web-Techniken im Einsatz',
        'csp-xss'                       => 'Kein Schutz gegen Cross-Site-Scripting (CSP)',
        // SEO
        'meta-description'              => 'Meta-Beschreibung fehlt',
        'document-title-seo'            => 'Seitentitel fehlt',
        'link-text'                     => 'Nicht aussagekräftige Linktexte',
        'crawlable-anchors'             => 'Links für Suchmaschinen nicht folgbar',
        'is-crawlable'                  => 'Seite für Suchmaschinen blockiert',
        'hreflang'                      => 'hreflang fehlerhaft (Sprachversionen)',
        'canonical'                     => 'Canonical-Link fehlerhaft',
    ];

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
            // Verbesserbare + bestandene Audits je Kategorie (deutsch), für die Empfehlungen.
            'findings'       => self::findings($cats, $audits),
        ];
    }

    /**
     * Je Kategorie: verbesserbare Audits (deutsch, wichtigste zuerst nach Impact) + Anzahl
     * bestandener. Informative/manuelle/nicht-anwendbare Audits werden ignoriert.
     * @return array<string,array{improve:array<int,string>,passed:int}>
     */
    private static function findings(array $cats, array $audits): array
    {
        $out = [];
        foreach (self::CATEGORIES as $catKey) {
            $refs = $cats[$catKey]['auditRefs'] ?? [];
            $improve = [];
            $passed = 0;
            // auditRefs sind nach Gewicht/Impact sortiert → Reihenfolge beibehalten.
            foreach ($refs as $ref) {
                $a = $audits[$ref['id']] ?? null;
                if ($a === null || ($a['score'] ?? null) === null) {
                    continue;
                }
                if (in_array($a['scoreDisplayMode'] ?? '', ['notApplicable', 'manual', 'informative'], true)) {
                    continue;
                }
                if ((float) $a['score'] < 0.9) {
                    $improve[] = self::auditLabel($ref['id'], (string) ($a['title'] ?? $ref['id']));
                } else {
                    $passed++;
                }
            }
            $out[$catKey] = ['improve' => array_values(array_unique($improve)), 'passed' => $passed];
        }
        return $out;
    }

    /** Deutsche Bezeichnung eines Audits, sonst der (englische) Lighthouse-Titel. */
    private static function auditLabel(string $id, string $fallback): string
    {
        return self::AUDIT_DE[$id] ?? $fallback;
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
