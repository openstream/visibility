<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Liefert Ranking-Messwerte für eine Domain. Implementierungen: GSC (eigene
 * Property, echte Klicks/Impressionen/Position), DataForSEO SERP (beliebige Keywords).
 */
interface SerpProvider
{
    /**
     * @param array<int,string> $keywords  approved Keyword-Texte (id => keyword)
     * @return array<int,Measurement>       je Messwert (keywordId gesetzt, wo zuordenbar)
     */
    public function collect(array $keywords): array;

    /** Kurzname der Quelle (für Logging). */
    public function name(): string;
}
