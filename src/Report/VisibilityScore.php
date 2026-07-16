<?php

declare(strict_types=1);

namespace Openstream\Visibility\Report;

/**
 * Openstream Visibility Score (OVS) — die plattformübergreifende Dach-Kennzahl.
 * Misst „aktive Sichtkontakte" pro Monat: ein Mensch hat den Content aktiv konsumiert.
 *
 * Keine willkürlichen Gewichte: jede Komponente ist entweder eine ECHTE Aktion
 * (Klick, View, Öffnung, KI-Nennung) oder eine CTR-FUNDIERTE Schätzung (Impression ×
 * tatsächliche CTR, dieselbe Grösse wie ETV). Reine Klasse → voll testbar.
 *
 * @phpstan-type Inputs array{
 *   google_clicks?:int, google_impressions?:int, google_ctr?:float,
 *   bing_clicks?:int, geo_mentions?:int, social_views?:int, newsletter_opens?:int
 * }
 */
final class VisibilityScore
{
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

        $geo = max(0, (int) ($in['geo_mentions'] ?? 0));
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
            'ki_nennungen'            => 'Nennungen in KI-Antworten',
            'social_views'            => 'Social-Media-Views',
            'newsletter_oeffnungen'   => 'Newsletter-Öffnungen',
            default                   => $key,
        };
    }
}
