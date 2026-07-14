<?php

declare(strict_types=1);

namespace Openstream\Visibility\Onboarding;

/**
 * Erzeugt deterministisch sinnvolle Keyword-Kombinationen aus Plattformen und
 * Modifikatoren (Region + Rolle). Beispiel: "shopify" × "agentur" × "zürich"
 * → "shopify agentur zürich". Ergänzt die LLM-Vorschläge um systematische
 * Plattform-Expertise-Keywords (WordPress/WooCommerce/Shopify/Magento etc.).
 *
 * Nicht jede Kombination ist sinnvoll, daher gezielte Muster statt vollem
 * Kreuzprodukt: (1) Plattform pur, (2) Plattform + Rolle, (3) Plattform + Rolle +
 * Region, (4) Plattform + Region.
 */
final class KeywordCombiner
{
    /**
     * @param array<int,string> $platforms  z.B. ['wordpress','woocommerce','shopify','magento']
     * @param array<int,string> $roles      z.B. ['agentur','dienstleister','erstellen lassen','entwicklung']
     * @param array<int,string> $regions    z.B. ['schweiz','zürich']
     * @return array<int,string> deduplizierte, kleingeschriebene Keyword-Liste
     */
    public function combine(array $platforms, array $roles = [], array $regions = []): array
    {
        $out = [];
        $add = function (string $kw) use (&$out): void {
            $kw = trim(preg_replace('/\s+/', ' ', mb_strtolower($kw)));
            if ($kw !== '') {
                $out[$kw] = true;
            }
        };

        foreach ($platforms as $p) {
            $add($p);                                   // 1) Plattform pur

            foreach ($roles as $r) {
                $add("{$p} {$r}");                      // 2) Plattform + Rolle
                foreach ($regions as $reg) {
                    $add("{$p} {$r} {$reg}");           // 3) Plattform + Rolle + Region
                }
            }

            foreach ($regions as $reg) {
                $add("{$p} {$reg}");                    // 4) Plattform + Region
            }
        }

        return array_keys($out);
    }

    /**
     * Bequemer Wrapper, der die Combiner-Config aus der Kunden-YAML liest.
     * Erwartet einen `keyword_combinations`-Block mit platforms/roles/regions.
     *
     * @param array<string,mixed> $cfg
     * @return array<int,string>
     */
    public function fromConfig(array $cfg): array
    {
        $c = $cfg['keyword_combinations'] ?? null;
        if (!$c || empty($c['platforms'])) {
            return [];
        }
        return $this->combine(
            (array) $c['platforms'],
            (array) ($c['roles'] ?? []),
            (array) ($c['regions'] ?? []),
        );
    }
}
