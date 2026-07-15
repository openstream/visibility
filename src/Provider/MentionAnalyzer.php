<?php

declare(strict_types=1);

namespace Openstream\Visibility\Provider;

/**
 * Wertet eine KI-Antwort (Text + zitierte URLs) daraufhin aus, ob die eigene Marke/
 * Domain erwähnt (mentioned) und/oder zitiert (cited) wird, an welcher Position im
 * Text, und welche Wettbewerber genannt werden. Rein & testbar (keine API).
 */
final class MentionAnalyzer
{
    /**
     * @param array<int,string> $brandNames  eigene Marken (z.B. ["Openstream","The Openstream"])
     * @param array<int,string> $competitors bekannte Wettbewerber-Domains/-Namen
     */
    public function __construct(
        private readonly string $domain,
        private readonly array $brandNames = [],
        private readonly array $competitors = [],
    ) {}

    /**
     * @param string             $text      die KI-Antwort (Fliesstext)
     * @param array<int,string>  $citations zitierte URLs
     * @return array{mentioned:bool,cited:bool,position:?int,competitors:array<int,string>}
     */
    public function analyze(string $text, array $citations): array
    {
        $needle = $this->domainNeedle($this->domain);
        $lcText = mb_strtolower($text);

        // Erwähnt: Domain oder eine der Marken taucht im Text auf.
        $terms = array_filter(array_merge([$needle], array_map('mb_strtolower', $this->brandNames)));
        $mentioned = false;
        $position = null;
        foreach ($terms as $t) {
            $pos = mb_strpos($lcText, $t);
            if ($pos !== false) {
                $mentioned = true;
                // Position = Rangnäherung: an welcher Stelle im Text (früher = prominenter).
                $position = $position === null ? $this->rankByOffset($lcText, $pos) : min($position, $this->rankByOffset($lcText, $pos));
            }
        }

        // Zitiert: Domain kommt in einer der Citation-URLs vor.
        $cited = false;
        foreach ($citations as $url) {
            if (str_contains(mb_strtolower($url), $needle)) {
                $cited = true;
                break;
            }
        }

        // Wettbewerber: welche der bekannten Konkurrenten im Text vorkommen.
        $found = [];
        foreach ($this->competitors as $c) {
            $cn = mb_strtolower($this->domainNeedle($c));
            if ($cn !== '' && str_contains($lcText, $cn)) {
                $found[] = $c;
            }
        }

        return [
            'mentioned'   => $mentioned,
            'cited'       => $cited,
            'position'    => $position,
            'competitors' => $found,
        ];
    }

    /** Position als grober Rang: 1 wenn im ersten Textdrittel, 2 zweites, 3 letztes. */
    private function rankByOffset(string $text, int $offset): int
    {
        $len = max(1, mb_strlen($text));
        $frac = $offset / $len;
        return $frac < 0.33 ? 1 : ($frac < 0.66 ? 2 : 3);
    }

    private function domainNeedle(string $domain): string
    {
        $d = strtolower(trim($domain));
        $d = preg_replace('#^https?://#', '', $d);
        $d = preg_replace('#^www\.#', '', (string) $d);
        return rtrim((string) $d, '/');
    }
}
