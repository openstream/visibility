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
 * Theme-adaptiv: Farben laufen über CSS-Variablen mit `@media (prefers-color-scheme:
 * dark)`-Overrides (im `<style>`-Block je SVG). Dieselbe Datei sieht in Light- UND
 * Dark-Mode-Viewern gut aus (z. B. Markdown-Renderer im Dark Mode). Jede Variable hat
 * einen Light-Fallback (`var(--x, #hex)`), damit Viewer ohne `<style>`-Support korrekt
 * rendern. In beiden Modi im Browser verifiziert.
 */
final class SvgChart
{
    // Farben als CSS-Variablen mit Light-Fallback, damit dieselbe SVG-Datei in
    // Light- UND Dark-Mode funktioniert (via @media prefers-color-scheme, s. open()).
    // Der Fallback nach dem Komma greift in Viewern, die <style> in SVG ignorieren.
    private const ACCENT   = 'var(--accent,#2563eb)';  // Blau (Kernserie)
    private const ACCENT_2 = 'var(--accent2,#60a5fa)'; // helleres Blau (zweite Serie)
    private const INK      = 'var(--ink,#1f2937)';     // Text
    private const MUTED    = 'var(--muted,#6b7280)';   // Achsen/Sekundärtext
    private const GRID     = 'var(--grid,#e5e7eb)';    // Gitterlinien
    private const TRACK    = 'var(--track,#eef2f7)';   // Balken-Hintergrund

    // Donut-Segmentfarben (erste = Akzent, Rest abgestufte Grautöne).
    // Als CSS-Variablen, damit die Grautöne im Dark Mode aufgehellt werden.
    private const DONUT = [
        'var(--accent,#2563eb)',
        'var(--d1,#64748b)',
        'var(--d2,#94a3b8)',
        'var(--d3,#cbd5e1)',
        'var(--d4,#e2e8f0)',
        'var(--d5,#f1f5f9)',
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

        $w = 720;
        $h = 260;
        $padT = $title !== '' ? 44 : 16;
        $cx = 130;
        $cy = $padT + (($h - $padT) / 2) - 8;
        $rOuter = 90;
        $rInner = 54;

        $svg = $this->open($w, $h);
        if ($title !== '') {
            $svg .= $this->titleEl($title, 16);
        }

        $angle = -90.0; // Start oben
        $legendX = 260;
        $legendY = $padT + 6;
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
            $svg .= sprintf('<rect x="%d" y="%.1f" width="12" height="12" rx="2" fill="%s"/>', $legendX, $legendY, $color);
            $svg .= $this->text(
                "{$s['label']}  {$pct} %",
                $legendX + 20,
                $legendY + 11,
                self::INK,
                13,
                'start'
            );
            $legendY += 24;
            $i++;
        }

        return $svg . '</svg>';
    }

    // --- SVG-Primitive -----------------------------------------------------

    private function open(int $w, int $h): string
    {
        // CSS-Variablen: Light-Defaults im :root, Dark-Overrides via prefers-color-scheme.
        // Dieselbe Datei passt sich so dem Modus des Betrachters an (Report-Viewer, Browser).
        // Dark-Palette: dunkler, aber nicht schwarzer Hintergrund; Text/Achsen aufgehellt;
        // Akzent-Blau heller (besserer Kontrast auf Dunkel); Track/Grautöne angehoben.
        $style = '<style>'
            . ':root{--bg:#ffffff;--ink:#1f2937;--muted:#6b7280;--grid:#e5e7eb;--track:#eef2f7;'
            . '--accent:#2563eb;--accent2:#60a5fa;'
            . '--d1:#64748b;--d2:#94a3b8;--d3:#cbd5e1;--d4:#e2e8f0;--d5:#f1f5f9;}'
            . '@media (prefers-color-scheme:dark){:root{'
            . '--bg:#1e2129;--ink:#e5e7eb;--muted:#9aa4b2;--grid:#3a404b;--track:#2b303a;'
            . '--accent:#5b9bff;--accent2:#93c5fd;'
            . '--d1:#94a3b8;--d2:#7c8aa0;--d3:#647184;--d4:#4b5563;--d5:#3a404b;}}'
            . '</style>';

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" '
            . 'font-family="-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
            . '%s<rect width="%d" height="%d" fill="var(--bg,#ffffff)"/>',
            $w, $h, $w, $h, $style, $w, $h
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
