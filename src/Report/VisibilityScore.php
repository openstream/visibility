<?php

declare(strict_types=1);

namespace Openstream\Visibility\Report;

/**
 * Openstream Visibility Score (OVS) — die plattformübergreifende Dach-Kennzahl.
 * Misst „aktive Sichtkontakte" pro Monat: ein Mensch hat den Content aktiv konsumiert.
 *
 * Basis: jede Komponente ist entweder eine ECHTE Aktion (Klick, View, Öffnung,
 * KI-Nennung) oder eine CTR-FUNDIERTE Schätzung (Impression × tatsächliche CTR,
 * dieselbe Grösse wie ETV). Die einzige bewusste Ausnahme von 1:1: KI-Nennungen
 * werden mit GEO_WEIGHT höher gewichtet — eine Nennung/Zitierung in einer KI-Antwort
 * ist erfahrungsgemäss conversion-stärker als ein klassischer Klick (der Nutzer
 * bekommt eine qualifizierte Empfehlung statt einer blauen Linkliste). Bewusst
 * konservativ (Faktor 2) und im Report offengelegt. Reine Klasse → voll testbar.
 *
 * @phpstan-type Inputs array{
 *   google_clicks?:int, google_impressions?:int, google_ctr?:float,
 *   bing_clicks?:int, geo_mentions?:int, social_views?:int, newsletter_opens?:int
 * }
 */
final class VisibilityScore
{
    /**
     * Gewicht einer KI-Nennung relativ zu einem Klick (=1). Conversion-stärker, s.
     * Klassen-Doku. Konservativ gewählt; bei Anpassung auch den Report-Hinweis und
     * das Komponenten-Label mitziehen.
     */
    public const GEO_WEIGHT = 2;

    /**
     * Berechnet den OVS und die Zusammensetzung je Kanal.
     * @param array<string,mixed> $in
     * @return array{score:int, components:array<string,int>}
     */
    public static function compute(array $in): array
    {
        $clicksG = max(0, (int) ($in['google_clicks'] ?? 0));
        $clicksB = max(0, (int) ($in['bing_clicks'] ?? 0));
        // Impression × reale CTR (CTR in Prozent → /100). Ehrlicher Erwartungswert an Besuchen.
        $impr = max(0, (int) ($in['google_impressions'] ?? 0));
        $ctr = max(0.0, (float) ($in['google_ctr'] ?? 0.0)) / 100.0;
        $imprWeighted = (int) round($impr * $ctr);

        // KI-Nennungen höher gewichtet (conversion-stärker als ein Klick, s. Klassen-Doku).
        $geo = max(0, (int) ($in['geo_mentions'] ?? 0)) * self::GEO_WEIGHT;
        $socialViews = max(0, (int) ($in['social_views'] ?? 0));
        $nlOpens = max(0, (int) ($in['newsletter_opens'] ?? 0));

        $components = [
            'google_klicks'          => $clicksG,
            'bing_klicks'            => $clicksB,
            'google_impressionen_ctr' => $imprWeighted,
            'ki_nennungen'           => $geo,
            'social_views'           => $socialViews,
            'newsletter_oeffnungen'  => $nlOpens,
        ];
        // Nur Komponenten mit Beitrag behalten (saubere Zusammensetzung im Report).
        $components = array_filter($components, static fn(int $v): bool => $v > 0);

        return ['score' => array_sum($components), 'components' => $components];
    }

    /** Menschenlesbares Label je Komponenten-Schlüssel (für den Report). */
    public static function label(string $key): string
    {
        return match ($key) {
            'google_klicks'           => 'Google-Klicks',
            'bing_klicks'             => 'Bing-Klicks',
            'google_impressionen_ctr' => 'Google-Impressionen (× Klickrate)',
            'ki_nennungen'            => 'Nennungen in KI-Antworten (× ' . self::GEO_WEIGHT . ')',
            'social_views'            => 'Social-Media-Views',
            'newsletter_oeffnungen'   => 'Newsletter-Öffnungen',
            default                   => $key,
        };
    }
}
