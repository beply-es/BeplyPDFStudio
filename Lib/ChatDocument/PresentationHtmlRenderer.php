<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

/**
 * HTML de una presentación (`beply.presentation/v1`) para el PDF de WeasyPrint
 * (`PdfStudioChatDocument?id=N&format=pdf`): una página 16:9 por diapositiva con la
 * misma maquetación que el PPTX y el visor del chat (portada en el color primario,
 * título con filete, viñetas, tabla, cifras clave y gráficos en SVG en línea).
 * Sin scripts ni recursos externos: el logo llega como data URI.
 */
final class PresentationHtmlRenderer
{
    public static function render(array $spec, array $branding, string $logoDataUri = '', string $fontFaces = ''): string
    {
        $t = OfficeTheme::fromBranding($branding);
        $company = (string) ($branding['company']['name'] ?? '');
        $slides = array_values((array) ($spec['slides'] ?? []));
        $total = count($slides);
        $e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $logo = $logoDataUri !== '' && preg_match('#^data:image/(png|jpeg|gif);base64,[A-Za-z0-9+/=]+$#', $logoDataUri) === 1
            ? '<img class="logo" src="' . $logoDataUri . '" alt="">' : '';

        $body = '';
        foreach ($slides as $index => $slide) {
            $number = $index + 1;
            $title = $e((string) $slide['title']);
            switch ($slide['layout']) {
                case 'title':
                    $subtitle = (string) ($slide['subtitle'] ?? ($spec['subtitle'] ?? ''));
                    $date = isset($spec['date']) ? PresentationSpec::humanDate((string) $spec['date']) : '';
                    $body .= '<section class="slide slide--title"><div class="cover"><h1>' . $title . '</h1>'
                        . ($subtitle !== '' ? '<p class="subtitle">' . $e($subtitle) . '</p>' : '')
                        . ($date !== '' ? '<p class="date">' . $e($date) . '</p>' : '') . '</div>'
                        . '<div class="band">' . $logo . '<span>' . $e($company) . '</span></div></section>';
                    continue 2;
                case 'section':
                    $body .= '<section class="slide slide--section"><div class="stripe"></div><h1>' . $title . '</h1>'
                        . (($slide['subtitle'] ?? '') !== '' ? '<p class="subtitle">' . $e((string) $slide['subtitle']) . '</p>' : '')
                        . self::footer($company, $number, $total, $logo, $e) . '</section>';
                    continue 2;
            }
            $content = '';
            switch ($slide['layout']) {
                case 'bullets':
                    $content = '<ul class="bullets">';
                    foreach ($slide['bullets'] as $bullet) {
                        $content .= '<li>' . preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $e((string) $bullet)) . '</li>';
                    }
                    $content .= '</ul>';
                    break;
                case 'table':
                    $content = '<table><thead><tr>';
                    foreach ($slide['header'] as $label) {
                        $content .= '<th>' . $e((string) $label) . '</th>';
                    }
                    $content .= '</tr></thead><tbody>';
                    foreach ($slide['rows'] as $row) {
                        $content .= '<tr>';
                        foreach ($row as $cell) {
                            $numeric = preg_match('/^[-+]?[\d.,\s]+(\s?[€$%]|\s?[A-Z]{3})?$/u', (string) $cell) === 1;
                            $content .= '<td' . ($numeric ? ' class="num"' : '') . '>' . $e((string) $cell) . '</td>';
                        }
                        $content .= '</tr>';
                    }
                    $content .= '</tbody></table>';
                    break;
                case 'chart':
                    $content = '<div class="chart">' . self::svgChart($slide['chart'], $t, $e) . '</div>';
                    break;
                case 'kpis':
                    $content = '<div class="kpis' . (count($slide['items']) > 3 ? ' kpis--compact' : '') . '">';
                    foreach ($slide['items'] as $item) {
                        $content .= '<div class="kpi"><div class="value">' . $e((string) $item['value']) . '</div><div class="label">' . $e((string) $item['label']) . '</div>'
                            . ($item['detail'] !== '' ? '<div class="detail">' . $e((string) $item['detail']) . '</div>' : '') . '</div>';
                    }
                    $content .= '</div>';
                    break;
            }
            $bottom = (string) ($slide['note'] ?? ($slide['caption'] ?? ''));
            $body .= '<section class="slide slide--' . $slide['layout'] . '"><h2>' . $title . '</h2><div class="rule"></div><div class="body">' . $content . '</div>'
                . ($bottom !== '' ? '<p class="note">' . $e($bottom) . '</p>' : '')
                . self::footer($company, $number, $total, $logo, $e) . '</section>';
        }

        $font = $e($t['font']['css']);
        $css = '@page { size: 338.67mm 190.5mm; margin: 0; }'
            . '* { box-sizing: border-box; } body { margin: 0; font-family: ' . $t['font']['css'] . '; color: ' . $t['text'] . '; }'
            . '.slide { position: relative; width: 338.67mm; height: 190.5mm; padding: 11mm 15mm 16mm; page-break-after: always; overflow: hidden; }'
            . '.slide:last-child { page-break-after: auto; }'
            . 'h2 { margin: 0; color: ' . $t['primary'] . '; font-size: 28pt; } .rule { width: 30mm; height: 1.5mm; margin: 4mm 0 7mm; background: ' . $t['secondary'] . '; }'
            . '.slide--title { padding: 0; background: ' . $t['primary'] . '; color: ' . $t['headerText'] . '; }'
            . '.cover { padding: 40mm 20mm 0; } .cover h1 { margin: 0; font-size: 44pt; } .cover .subtitle { font-size: 22pt; margin: 6mm 0 0; } .cover .date { font-size: 16pt; margin: 3mm 0 0; }'
            . '.band { position: absolute; left: 0; right: 0; bottom: 0; height: 30mm; background: #FFFFFF; display: flex; align-items: center; justify-content: space-between; padding: 0 20mm; color: ' . $t['secondary'] . '; font-size: 14pt; }'
            . '.slide--section .stripe { position: absolute; left: 0; top: 0; bottom: 0; width: 9mm; background: ' . $t['primary'] . '; }'
            . '.slide--section h1 { margin: 55mm 0 0 10mm; color: ' . $t['primary'] . '; font-size: 40pt; } .slide--section .subtitle { margin: 5mm 0 0 10mm; color: ' . $t['secondary'] . '; font-size: 20pt; }'
            . '.bullets { margin: 0; padding-left: 8mm; font-size: 20pt; } .bullets li { margin-bottom: 4mm; } .bullets li::marker { color: ' . $t['secondary'] . '; }'
            . 'table { width: 100%; border-collapse: collapse; font-size: 14pt; } th { background: ' . $t['primary'] . '; color: ' . $t['headerText'] . '; text-align: left; padding: 2.5mm 3mm; }'
            . 'td { padding: 2.5mm 3mm; border-bottom: 0.3mm solid #D1D5DB; } tbody tr:nth-child(even) td { background: ' . $t['zebra'] . '; } .num { text-align: right; }'
            . '.chart svg { width: 100%; height: 115mm; } .kpis { display: flex; gap: 8mm; margin-top: 8mm; }'
            . '.kpi { flex: 1; background: ' . $t['zebra'] . '; border-left: 2mm solid ' . $t['primary'] . '; padding: 10mm 8mm; }'
            . '.kpi .value { color: ' . $t['primary'] . '; font-size: 34pt; font-weight: 700; } .kpis--compact .kpi .value { font-size: 25pt; } .kpi .label { font-size: 16pt; font-weight: 700; margin-top: 2mm; } .kpi .detail { color: ' . $t['secondary'] . '; font-size: 13pt; margin-top: 2mm; }'
            . '.note { position: absolute; left: 15mm; right: 15mm; bottom: 16mm; margin: 0; color: ' . $t['secondary'] . '; font-size: 12pt; }'
            . '.foot { position: absolute; left: 15mm; right: 15mm; bottom: 6mm; display: flex; justify-content: space-between; color: ' . $t['secondary'] . '; font-size: 10pt; }'
            . '.slide:not(.slide--title) .logo { position: absolute; top: 10mm; right: 13mm; max-height: 15mm; max-width: 45mm; } .band .logo { max-height: 18mm; max-width: 60mm; }';

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>' . $e((string) ($spec['title'] ?? 'Presentación')) . '</title>'
            . '<style>' . $fontFaces . $css . '</style><meta name="beply-font" content="' . $font . '"></head><body>' . $body . '</body></html>';
    }

    private static function footer(string $company, int $number, int $total, string $logo, callable $e): string
    {
        return $logo . '<div class="foot"><span>' . $e($company) . '</span><span>' . $number . ' / ' . $total . '</span></div>';
    }

    /**
     * Escala «redonda» del eje de valores: [mínimo, máximo, paso] con pasos 1, 2, 2,5 o 5
     * por potencia de diez y unas cinco divisiones como mucho (la misma regla que el visor del chat).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public static function niceScale(float $min, float $max): array
    {
        $min = min(0.0, $min);
        $max = max(0.0, $max);
        $raw = (($max - $min) ?: 1.0) / 5;
        $magnitude = 10 ** floor(log10($raw));
        $step = $magnitude * 10;
        foreach ([1, 2, 2.5, 5, 10] as $factor) {
            if ($raw <= $factor * $magnitude) {
                $step = $factor * $magnitude;
                break;
            }
        }

        return [floor($min / $step) * $step, ceil($max / $step) * $step, $step];
    }

    /** Gráfico de barras, columnas, líneas o tarta en SVG (sin scripts). */
    public static function svgChart(array $chart, array $theme, callable $e): string
    {
        $palette = PresentationPptxWriter::palette($theme);
        $categories = $chart['categories'];
        $series = $chart['series'];
        $w = 1000;
        $h = 440;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . $e(implode(', ', $categories)) . '" font-family="' . $e($theme['font']['css']) . '" font-size="16">';

        if ($chart['type'] === 'pie') {
            $values = array_map(static fn($v): float => max(0.0, (float) $v), $series[0]['values'] ?? []);
            $sum = array_sum($values) ?: 1.0;
            $angle = -M_PI / 2;
            $cx = 300;
            $cy = 220;
            $r = 190;
            foreach ($values as $i => $value) {
                $sweep = $value / $sum * 2 * M_PI;
                $x1 = $cx + $r * cos($angle);
                $y1 = $cy + $r * sin($angle);
                $x2 = $cx + $r * cos($angle + $sweep);
                $y2 = $cy + $r * sin($angle + $sweep);
                $color = $palette[$i % count($palette)];
                $svg .= $value >= $sum
                    ? '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="' . $color . '"/>'
                    : sprintf('<path d="M%d %d L%.1f %.1f A%d %d 0 %d 1 %.1f %.1f Z" fill="%s" stroke="#FFFFFF" stroke-width="2"/>', $cx, $cy, $x1, $y1, $r, $r, $sweep > M_PI ? 1 : 0, $x2, $y2, $color);
                $svg .= '<rect x="560" y="' . (60 + $i * 34) . '" width="18" height="18" fill="' . $color . '"/>'
                    . '<text x="590" y="' . (75 + $i * 34) . '" fill="' . $theme['text'] . '">' . $e((string) ($categories[$i] ?? '')) . ' · ' . number_format($value / $sum * 100, 0, ',', '.') . ' %</text>';
                $angle += $sweep;
            }
            return $svg . '</svg>';
        }

        $all = array_merge(...array_map(static fn(array $s): array => array_map('floatval', $s['values']), $series));
        if ($chart['type'] === 'bar') {
            // Barras horizontales: categorías en el eje vertical.
            $max = max(array_merge([0.0], $all)) ?: 1.0;
            $left = 220;
            $plotW = $w - $left - 120;
            $plotH = $h - 20 - (count($series) > 1 ? 50 : 10);
            $slot = $plotH / max(1, count($categories));
            $barH = $slot * 0.7 / max(1, count($series));
            foreach ($categories as $c => $category) {
                $svg .= sprintf('<text x="%d" y="%.1f" text-anchor="end" fill="%s">%s</text>', $left - 10, 20 + $slot * ($c + 0.5) + 5, $theme['text'], $e((string) $category));
                foreach ($series as $s => $serie) {
                    $value = max(0.0, (float) ($serie['values'][$c] ?? 0));
                    $by = 20 + $slot * $c + $slot * 0.15 + $barH * $s;
                    $svg .= sprintf('<rect x="%d" y="%.1f" width="%.1f" height="%.1f" fill="%s"/>', $left, $by, $value / $max * $plotW, $barH, $palette[$s % count($palette)])
                        . sprintf('<text x="%.1f" y="%.1f" fill="%s">%s</text>', $left + $value / $max * $plotW + 8, $by + $barH / 2 + 5, $theme['secondary'], $e(number_format($value, 0, ',', '.') . (isset($chart['unit']) ? ' ' . $chart['unit'] : '')));
                }
            }
            if (count($series) > 1) {
                foreach ($series as $s => $serie) {
                    $svg .= '<rect x="' . ($left + $s * 180) . '" y="' . ($h - 28) . '" width="16" height="16" fill="' . $palette[$s % count($palette)] . '"/>'
                        . '<text x="' . ($left + $s * 180 + 24) . '" y="' . ($h - 14) . '" fill="' . $theme['text'] . '">' . $e((string) $serie['name']) . '</text>';
                }
            }
            return $svg . '</svg>';
        }
        [$min, $max, $step] = self::niceScale(min(array_merge([0.0], $all)), max(array_merge([0.0], $all)));
        $span = ($max - $min) ?: 1.0;
        $left = 90;
        $top = 20;
        $plotW = $w - $left - 20;
        $plotH = $h - $top - (count($series) > 1 ? 80 : 50);
        $y = static fn(float $v): float => $top + ($max - $v) / $span * $plotH;
        for ($value = $min; $value <= $max + $step / 2; $value += $step) {
            $svg .= sprintf('<line x1="%d" x2="%d" y1="%.1f" y2="%.1f" stroke="#E5E7EB"/>', $left, $left + $plotW, $y($value), $y($value))
                . sprintf('<text x="%d" y="%.1f" text-anchor="end" fill="%s">%s</text>', $left - 8, $y($value) + 5, $theme['secondary'], $e(number_format($value, 0, ',', '.') . (isset($chart['unit']) ? ' ' . $chart['unit'] : '')));
        }
        $n = max(1, count($categories));
        $slot = $plotW / $n;
        foreach ($categories as $c => $category) {
            $svg .= sprintf('<text x="%.1f" y="%d" text-anchor="middle" fill="%s">%s</text>', $left + $slot * ($c + 0.5), $top + $plotH + 26, $theme['text'], $e((string) $category));
        }
        foreach ($series as $s => $serie) {
            $color = $palette[$s % count($palette)];
            if ($chart['type'] === 'line') {
                $points = [];
                foreach ($serie['values'] as $c => $value) {
                    $points[] = sprintf('%.1f,%.1f', $left + $slot * ($c + 0.5), $y((float) $value));
                }
                $svg .= '<polyline fill="none" stroke="' . $color . '" stroke-width="4" points="' . implode(' ', $points) . '"/>';
                foreach ($points as $point) {
                    [$px, $py] = explode(',', $point);
                    $svg .= '<circle cx="' . $px . '" cy="' . $py . '" r="6" fill="' . $color . '"/>';
                }
            } else {
                $barW = $slot * 0.7 / max(1, count($series));
                foreach ($serie['values'] as $c => $value) {
                    $x = $left + $slot * $c + $slot * 0.15 + $barW * $s;
                    $y0 = $y(0.0);
                    $y1 = $y((float) $value);
                    $svg .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"/>', $x, min($y0, $y1), $barW, abs($y0 - $y1), $color);
                }
            }
        }
        if (count($series) > 1) {
            foreach ($series as $s => $serie) {
                $svg .= '<rect x="' . ($left + $s * 180) . '" y="' . ($h - 28) . '" width="16" height="16" fill="' . $palette[$s % count($palette)] . '"/>'
                    . '<text x="' . ($left + $s * 180 + 24) . '" y="' . ($h - 14) . '" fill="' . $theme['text'] . '">' . $e((string) $serie['name']) . '</text>';
            }
        }

        return $svg . '</svg>';
    }
}
