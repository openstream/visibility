<?php

declare(strict_types=1);

namespace Openstream\Visibility\Chart;

/**
 * Schlanker, abhängigkeitsfreier SVG-Chart-Generator für den `.md`-Report.
 *
 * Kein externer Dienst (kein QuickChart), keine Netzwerk-Calls: alle Diagramme
 * werden als eigenständiges SVG erzeugt und als Datei unter
 * `storage/reports/<kunde>/charts/` abgelegt, im Report relativ eingebettet
 * (`![...](charts/....svg)`). SVG rendert scharf in GitHub/Browsern.
 *
 * Deutsche Zahlenformatierung (Tausender ' , Dezimal ,). Farben zurückhaltend,
 * ein Akzent-Blau plus Grautöne, passend zum sachlichen Report.
 *
 * EIN statisches Farbschema für Light UND Dark — kein dynamisches CSS.
 * Bewusst KEINE `@media (prefers-color-scheme)`-Query: GitHub rendert eingebettete
 * SVGs in einem Sandbox-Frame und wertet die Query unzuverlässig aus (Hintergrund
 * flackerte beim Reload zwischen hell/dunkel). Stattdessen:
 *  - transparenter Hintergrund → die Seitenfarbe scheint durch, passt sich immer an;
 *  - mittleres Grau für Text/Achsen (auf Weiss UND Dunkelgrau lesbar);
 *  - halbtransparente Grautöne (rgba) für Gitter/Track/Donut → dezent auf beiden.
 * In beiden Modi auf GitHub verifiziert.
 */
final class SvgChart
{
    // Statische Palette, in Light UND Dark tragfähig. Akzent-Blau #3b82f6 hat auf
    // Weiss und auf Dunkelgrau je genug Kontrast. Text/Achsen mittelgrau. Flächen
    // (Gitter, Track, Donut-Grautöne) halbtransparent, damit sie sich dem Untergrund
    // anpassen statt fest hell/dunkel zu sein.
    private const ACCENT   = '#3b82f6';                 // Blau (Kernserie)
    private const ACCENT_2 = '#93c5fd';                 // helleres Blau (zweite Serie)
    private const INK      = '#7d8794';                 // Text (mittelgrau, beidseitig lesbar)
    private const MUTED    = '#7d8794';                 // Achsen/Sekundärtext
    private const GRID      = 'rgba(128,140,160,0.30)'; // Gitterlinien
    private const TRACK     = 'rgba(128,140,160,0.18)'; // Balken-Hintergrund

    // Donut-Segmentfarben: erste = Akzent, Rest halbtransparente Grautöne
    // (unterscheidbar auf hellem wie dunklem Hintergrund).
    private const DONUT = [
        '#3b82f6',
        'rgba(128,140,160,0.85)',
        'rgba(128,140,160,0.62)',
        'rgba(128,140,160,0.44)',
        'rgba(128,140,160,0.30)',
        'rgba(128,140,160,0.20)',
    ];

    /**
     * Liniendiagramm einer Zeitreihe (z. B. ETV-Verlauf über Monate).
     *
     * @param array<int,string> $labels x-Achsen-Beschriftungen (z. B. "Jul 26")
     * @param array<int,float>  $values zugehörige Werte
     */
    public function line(array $labels, array $values, string $title = '', string $unit = ''): string
    {
        $w = 720;
        $h = 300;
        $padL = 64;
        $padR = 20;
        $padT = $title !== '' ? 44 : 20;
        $padB = 40;
        $plotW = $w - $padL - $padR;
        $plotH = $h - $padT - $padB;

        $n = count($values);
        $max = $values ? max($values) : 0.0;
        $min = 0.0; // Nulllinie als Basis: Sichtbarkeit relativ zu 0 lesen
        $niceMax = $this->niceCeil($max);
        $range = ($niceMax - $min) ?: 1;

        $x = fn(int $i): float => $n <= 1
            ? $padL + $plotW / 2
            : $padL + $i / ($n - 1) * $plotW;
        $y = fn(float $v): float => $padT + $plotH - (($v - $min) / $range) * $plotH;

        $svg = $this->open($w, $h);
        if ($title !== '') {
            $svg .= $this->titleEl($title, $padL);
        }

        // Horizontale Gitterlinien + y-Beschriftung (0, Mitte, Max).
        foreach ([0.0, 0.5, 1.0] as $frac) {
            $val = $min + $frac * $range;
            $gy = $y($val);
            $svg .= $this->hline($padL, $w - $padR, $gy);
            $svg .= $this->text(
                $this->num($val) . ($unit !== '' ? '' : ''),
                $padL - 8,
                $gy + 4,
                self::MUTED,
                12,
                'end'
            );
        }

        // Fläche unter der Linie (dezent), dann die Linie selbst.
        if ($n >= 1) {
            $pts = [];
            foreach ($values as $i => $v) {
                $pts[] = sprintf('%.1f,%.1f', $x($i), $y($v));
            }
            if ($n >= 2) {
                $area = sprintf('%.1f,%.1f ', $x(0), $y($min))
                    . implode(' ', $pts)
                    . sprintf(' %.1f,%.1f', $x($n - 1), $y($min));
                $svg .= sprintf('<polygon points="%s" fill="%s" opacity="0.08"/>', $area, self::ACCENT);
                $svg .= sprintf(
                    '<polyline points="%s" fill="none" stroke="%s" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>',
                    implode(' ', $pts),
                    self::ACCENT
                );
            }
            // Datenpunkte + x-Beschriftung.
            foreach ($values as $i => $v) {
                $svg .= sprintf('<circle cx="%.1f" cy="%.1f" r="3.5" fill="%s"/>', $x($i), $y($v), self::ACCENT);
                $lbl = $labels[$i] ?? '';
                if ($lbl !== '' && ($n <= 8 || $i % (int) ceil($n / 8) === 0 || $i === $n - 1)) {
                    $svg .= $this->text($lbl, $x($i), $h - $padB + 18, self::MUTED, 12, 'middle');
                }
            }
            // Letzten Wert als Label hervorheben.
            $lastV = $values[$n - 1];
            $svg .= $this->text(
                $this->num($lastV) . ($unit !== '' ? " {$unit}" : ''),
                $x($n - 1),
                $y($lastV) - 10,
                self::INK,
                12,
                'end',
                true
            );
        }

        return $svg . '</svg>';
    }

    /**
     * Horizontales Balkendiagramm (Momentaufnahme), z. B. Keyword-Verteilung
     * über Positions-Buckets oder GEO-Erwähnungsrate je Kanal.
     *
     * @param array<int,array{label:string,value:float,note?:string}> $rows
     * @param float|null $maxOverride feste Skala (z. B. 100 für Prozent); sonst auto
     */
    public function bars(array $rows, string $title = '', ?float $maxOverride = null, string $valueSuffix = ''): string
    {
        $rowH = 30;
        $gap = 12;
        $w = 720;
        $padL = 150; // Platz für Kategorie-Labels
        $padR = 64;  // Platz für Wert am Ende
        $padT = $title !== '' ? 44 : 16;
        $padB = 12;
        $barsN = count($rows);
        $h = $padT + $padB + $barsN * $rowH + max(0, $barsN - 1) * $gap;
        $plotW = $w - $padL - $padR;

        $max = $maxOverride ?? ($rows ? max(array_map(static fn($r) => (float) $r['value'], $rows)) : 0.0);
        $max = $max ?: 1.0;

        $svg = $this->open($w, $h);
        if ($title !== '') {
            $svg .= $this->titleEl($title, 16);
        }

        $yCur = $padT;
        foreach ($rows as $r) {
            $val = (float) $r['value'];
            $barW = max(0.0, min(1.0, $val / $max)) * $plotW;
            $barY = $yCur;
            $midY = $barY + $rowH / 2;

            // Kategorie-Label links.
            $svg .= $this->text($r['label'], $padL - 10, $midY + 4, self::INK, 13, 'end');
            // Track (voller Balken-Hintergrund) + Wertbalken.
            $svg .= sprintf(
                '<rect x="%d" y="%.1f" width="%.1f" height="%d" rx="4" fill="%s"/>',
                $padL, $barY, $plotW, $rowH, self::TRACK
            );
            $svg .= sprintf(
                '<rect x="%d" y="%.1f" width="%.1f" height="%d" rx="4" fill="%s"/>',
                $padL, $barY, $barW, $rowH, self::ACCENT
            );
            // Wert rechts vom Balken.
            $valTxt = $this->num($val) . $valueSuffix;
            $svg .= $this->text($valTxt, $padL + $plotW + 8, $midY + 4, self::INK, 13, 'start', true);

            $yCur += $rowH + $gap;
        }

        return $svg . '</svg>';
    }

    /**
     * Donut-Diagramm für Anteile (z. B. CH-Marktanteile). Kleine Reste werden
     * zu „Übrige" zusammengefasst, damit die Legende lesbar bleibt.
     *
     * @param array<int,array{label:string,value:float}> $slices  Werte (Prozent oder absolut)
     */
    public function donut(array $slices, string $title = '', float $groupBelow = 3.0): string
    {
        // Kleine Segmente bündeln.
        $total = array_sum(array_map(static fn($s) => (float) $s['value'], $slices)) ?: 1.0;
        $big = [];
        $rest = 0.0;
        foreach ($slices as $s) {
            if ((float) $s['value'] / $total * 100 < $groupBelow) {
                $rest += (float) $s['value'];
            } else {
                $big[] = $s;
            }
        }
        if ($rest > 0) {
            $big[] = ['label' => 'Übrige', 'value' => $rest];
        }

        // Kompakte viewBox ohne toten Raum rechts: grosser Donut links, Legende
        // rechts daneben. Bei voller Report-Breite wirkt der Donut dadurch deutlich
        // grösser als vorher (720px-Box mit halber Leerfläche).
        $w = 560;
        $h = 280;
        $padT = $title !== '' ? 44 : 16;
        $cx = 150;
        $cy = $padT + (($h - $padT) / 2);
        $rOuter = 108;
        $rInner = 64;

        $svg = $this->open($w, $h);
        if ($title !== '') {
            $svg .= $this->titleEl($title, 16);
        }

        // Legende vertikal in der Donut-Höhe zentrieren.
        $rowH = 26;
        $legendX = 300;
        $legendBlockH = count($big) * $rowH;
        $legendY = $cy - $legendBlockH / 2 + 4;
        $angle = -90.0; // Start oben
        $i = 0;
        foreach ($big as $s) {
            $frac = (float) $s['value'] / $total;
            $sweep = $frac * 360.0;
            $color = self::DONUT[$i % count(self::DONUT)];
            if ($frac > 0) {
                $svg .= $this->donutSegment($cx, $cy, $rOuter, $rInner, $angle, $angle + $sweep, $color);
            }
            $angle += $sweep;

            // Legende: Farbkästchen + Label + Prozent.
            $pct = $this->num(round($frac * 100, 1));
            $svg .= sprintf('<rect x="%d" y="%.1f" width="14" height="14" rx="3" fill="%s"/>', $legendX, $legendY, $color);
            $svg .= $this->text(
                "{$s['label']}  {$pct} %",
                $legendX + 22,
                $legendY + 12,
                self::INK,
                14,
                'start'
            );
            $legendY += $rowH;
            $i++;
        }

        return $svg . '</svg>';
    }

    // --- SVG-Primitive -----------------------------------------------------

    private function open(int $w, int $h): string
    {
        // Transparenter Hintergrund (kein <rect fill>): die Seitenfarbe des Viewers
        // scheint durch, dadurch passt sich der Chart automatisch an Light/Dark an,
        // ohne dynamisches CSS. Alle Vordergrundfarben sind beidseitig lesbar (s. o.).
        //
        // KEINE festen width/height-Attribute: nur die viewBox setzt das
        // Seitenverhältnis. So skaliert der Renderer das SVG auf die volle
        // Breite der Textspalte (statt es auf 720px zu begrenzen und winzig
        // wirken zu lassen). style="width:100%;height:auto" erzwingt das auch
        // in Viewern, die sonst die intrinsische Grösse verwenden.
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" '
            . 'style="width:100%%;height:auto" '
            . 'font-family="-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">',
            $w, $h
        );
    }

    private function titleEl(string $title, int $x): string
    {
        return $this->text($this->esc($title), $x, 24, self::INK, 15, 'start', true);
    }

    private function hline(float $x1, float $x2, float $y): string
    {
        return sprintf(
            '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="1"/>',
            $x1, $y, $x2, $y, self::GRID
        );
    }

    private function text(string $s, float $x, float $y, string $fill, int $size, string $anchor, bool $bold = false): string
    {
        return sprintf(
            '<text x="%.1f" y="%.1f" fill="%s" font-size="%d" text-anchor="%s"%s>%s</text>',
            $x, $y, $fill, $size, $anchor, $bold ? ' font-weight="600"' : '', $this->esc($s)
        );
    }

    /** Donut-Segment als Path (Aussen-Bogen hin, Innen-Bogen zurück). */
    private function donutSegment(float $cx, float $cy, float $rO, float $rI, float $a0, float $a1, string $color): string
    {
        $large = ($a1 - $a0) > 180 ? 1 : 0;
        [$x0o, $y0o] = $this->polar($cx, $cy, $rO, $a0);
        [$x1o, $y1o] = $this->polar($cx, $cy, $rO, $a1);
        [$x0i, $y0i] = $this->polar($cx, $cy, $rI, $a1);
        [$x1i, $y1i] = $this->polar($cx, $cy, $rI, $a0);
        return sprintf(
            '<path d="M %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f L %.2f %.2f A %.2f %.2f 0 %d 0 %.2f %.2f Z" fill="%s"/>',
            $x0o, $y0o, $rO, $rO, $large, $x1o, $y1o,
            $x0i, $y0i, $rI, $rI, $large, $x1i, $y1i,
            $color
        );
    }

    /** @return array{0:float,1:float} */
    private function polar(float $cx, float $cy, float $r, float $angleDeg): array
    {
        $rad = deg2rad($angleDeg);
        return [$cx + $r * cos($rad), $cy + $r * sin($rad)];
    }

    /** Rundet auf eine „schöne" Obergrenze (1/2/5 × 10^n) für die y-Skala. */
    private function niceCeil(float $v): float
    {
        if ($v <= 0) {
            return 1.0;
        }
        $exp = floor(log10($v));
        $base = 10 ** $exp;
        $frac = $v / $base;
        $nice = $frac <= 1 ? 1 : ($frac <= 2 ? 2 : ($frac <= 5 ? 5 : 10));
        return $nice * $base;
    }

    /** Deutsche Zahlenformatierung: Tausender-Apostroph, Dezimalkomma, ganzzahlig wenn möglich. */
    private function num(float $v): string
    {
        if (abs($v - round($v)) < 0.05) {
            return number_format($v, 0, ',', "'");
        }
        return number_format($v, 1, ',', "'");
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
