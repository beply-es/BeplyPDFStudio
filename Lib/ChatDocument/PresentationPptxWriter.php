<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

/**
 * Escritor PPTX propio (PresentationML con ZipArchive, como el DOCX y el XLSX) para
 * las presentaciones del chat (`beply.presentation/v1`).
 *
 * - Tema (`ppt/theme/theme1.xml`) con los colores y la fuente del estilo de PDF
 *   Studio: quien edita en PowerPoint sigue en la marca de la empresa.
 * - 16:9; portada con el color primario, sección, viñetas (`**negrita**`), tabla
 *   nativa (`a:tbl`), gráfico nativo (`c:chart` de barras, columnas, líneas o tarta,
 *   con los valores en caché; sin libro incrustado) y cifras clave.
 * - Logo en la portada y arriba a la derecha del resto; pie con la empresa y «n / N».
 */
final class PresentationPptxWriter
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    private const W = 12192000;
    private const H = 6858000;
    private const IN = 914400;
    private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

    private array $theme;
    private string $font;
    private string $company;
    private int $shapeId = 2;

    private function __construct(array $branding)
    {
        $this->theme = OfficeTheme::fromBranding($branding);
        $this->font = OoxmlPackage::xml($this->theme['font']['name']);
        $this->company = (string) ($branding['company']['name'] ?? '');
    }

    /** Bytes del .pptx. */
    public static function render(array $spec, array $branding, ?\DateTimeInterface $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $writer = new self($branding);
        $logo = OoxmlPackage::logo($branding, 45, 16);
        $slides = array_values((array) ($spec['slides'] ?? []));
        $total = count($slides);

        $slideParts = [];
        $charts = [];
        foreach ($slides as $index => $slide) {
            $chartIndex = null;
            if ($slide['layout'] === 'chart') {
                $charts[] = $writer->chartXml($slide['chart']);
                $chartIndex = count($charts);
            }
            $xml = $writer->slide($spec, $slide, $index + 1, $total, $logo);
            $rels = '<Relationship Id="rId1" Type="' . self::REL . 'slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
                . ($logo !== null ? '<Relationship Id="rIdLogo" Type="' . self::REL . 'image" Target="../media/logo.' . $logo['ext'] . '"/>' : '')
                . ($chartIndex !== null ? '<Relationship Id="rIdChart" Type="' . self::REL . 'chart" Target="../charts/chart' . $chartIndex . '.xml"/>' : '');
            $slideParts[] = [$xml, self::rels($rels)];
        }

        $parts = [
            '[Content_Types].xml' => self::contentTypes($total, count($charts), $logo),
            '_rels/.rels' => self::rels(
                '<Relationship Id="rId1" Type="' . self::REL . 'officeDocument" Target="ppt/presentation.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                . '<Relationship Id="rId3" Type="' . self::REL . 'extended-properties" Target="docProps/app.xml"/>'
            ),
            'ppt/presentation.xml' => self::presentation($total),
            'ppt/_rels/presentation.xml.rels' => self::presentationRels($total),
        ];
        foreach ($slideParts as $i => [$xml, $rels]) {
            $parts['ppt/slides/slide' . ($i + 1) . '.xml'] = $xml;
            $parts['ppt/slides/_rels/slide' . ($i + 1) . '.xml.rels'] = $rels;
        }
        foreach ($charts as $i => $xml) {
            $parts['ppt/charts/chart' . ($i + 1) . '.xml'] = $xml;
        }
        $parts['ppt/slideMasters/slideMaster1.xml'] = self::master();
        $parts['ppt/slideMasters/_rels/slideMaster1.xml.rels'] = self::rels(
            '<Relationship Id="rId1" Type="' . self::REL . 'slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            . '<Relationship Id="rId2" Type="' . self::REL . 'theme" Target="../theme/theme1.xml"/>'
        );
        $parts['ppt/slideLayouts/slideLayout1.xml'] = self::layout();
        $parts['ppt/slideLayouts/_rels/slideLayout1.xml.rels'] = self::rels('<Relationship Id="rId1" Type="' . self::REL . 'slideMaster" Target="../slideMasters/slideMaster1.xml"/>');
        $parts['ppt/theme/theme1.xml'] = $writer->themeXml();
        $parts['ppt/presProps.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentationPr ' . self::NS . '/>';
        $parts['ppt/viewProps.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:viewPr ' . self::NS . '><p:gridSpacing cx="76200" cy="76200"/></p:viewPr>';
        $parts['ppt/tableStyles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:tblStyleLst xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>';
        if ($logo !== null) {
            $parts['ppt/media/logo.' . $logo['ext']] = $logo['bytes'];
        }
        $parts['docProps/core.xml'] = OoxmlPackage::coreProps((string) ($spec['title'] ?? 'Presentación'), $now, $writer->company);
        $parts['docProps/app.xml'] = OoxmlPackage::appProps('Microsoft Office PowerPoint');

        return OoxmlPackage::zip($parts, 'bpptx');
    }

    // ------------------------------------------------------------------
    // Diapositivas
    // ------------------------------------------------------------------

    private function slide(array $spec, array $slide, int $number, int $total, ?array $logo): string
    {
        $this->shapeId = 2;
        $t = $this->theme;
        $shapes = '';
        $in = static fn(float $inches): int => (int) round($inches * self::IN);

        if ($slide['layout'] === 'title') {
            $shapes .= $this->rect(0, 0, self::W, self::H, $t['primary']);
            $shapes .= $this->rect(0, $in(6.3), self::W, $in(1.2), '#FFFFFF');
            $shapes .= $this->text($in(0.8), $in(1.7), $in(11.2), $in(1.6), [[$slide['title'], 4400, true, $t['headerText']]], 'l', 'b');
            $subtitle = $slide['subtitle'] ?? ($spec['subtitle'] ?? '');
            $lines = [];
            if ($subtitle !== '') {
                $lines[] = [$subtitle, 2200, false, $t['headerText']];
            }
            if (($spec['date'] ?? '') !== '') {
                $lines[] = [PresentationSpec::humanDate((string) $spec['date']), 1600, false, $t['headerText']];
            }
            if ($lines !== []) {
                $shapes .= $this->text($in(0.8), $in(3.45), $in(11.2), $in(1.4), $lines, 'l', 't');
            }
            if ($logo !== null) {
                $shapes .= $this->picture($logo, $in(0.8), $in(6.3) + (int) (($in(1.2) - $logo['cy']) / 2));
            }
            if ($this->company !== '') {
                $shapes .= $this->text($in(6.5), $in(6.45), $in(6.0), $in(0.6), [[$this->company, 1400, false, $t['secondary']]], 'r', 'ctr');
            }
            return $this->slideXml($shapes);
        }

        if ($slide['layout'] === 'section') {
            $shapes .= $this->rect(0, 0, $in(0.35), self::H, $t['primary']);
            $shapes .= $this->text($in(1.0), $in(2.4), $in(10.5), $in(1.4), [[$slide['title'], 4000, true, $t['primary']]], 'l', 'b');
            if (($slide['subtitle'] ?? '') !== '') {
                $shapes .= $this->text($in(1.0), $in(3.9), $in(10.5), $in(1.0), [[$slide['subtitle'], 2000, false, $t['secondary']]], 'l', 't');
            }
            return $this->slideXml($shapes . $this->footer($number, $total, $logo));
        }

        // Diapositivas de contenido: título, filete de acento, cuerpo y pie.
        $shapes .= $this->text($in(0.6), $in(0.35), $in(10.2), $in(0.95), [[$slide['title'], 2800, true, $t['primary']]], 'l', 'b');
        $shapes .= $this->rect($in(0.6), $in(1.35), $in(1.2), $in(0.06), $t['secondary']);
        $bodyY = $in(1.65);
        $bodyH = $in(4.75);
        $bottom = (string) ($slide['note'] ?? ($slide['caption'] ?? ''));
        if ($bottom !== '') {
            $bodyH -= $in(0.45);
        }

        switch ($slide['layout']) {
            case 'bullets':
                $size = count($slide['bullets']) > 5 ? 1800 : 2000;
                $paragraphs = '';
                foreach ($slide['bullets'] as $bullet) {
                    $paragraphs .= '<a:p><a:pPr marL="342900" indent="-342900"><a:spcBef><a:spcPts val="900"/></a:spcBef><a:buClr><a:srgbClr val="' . OoxmlPackage::hex($t['secondary']) . '"/></a:buClr><a:buFont typeface="Arial"/><a:buChar char="•"/></a:pPr>'
                        . $this->runs((string) $bullet, $size, $t['text']) . '</a:p>';
                }
                $shapes .= $this->textBody($in(0.6), $bodyY, $in(11.0), $bodyH, $paragraphs, 't');
                break;

            case 'table':
                $shapes .= $this->table($slide['header'], $slide['rows'], $in(0.6), $bodyY, $in(11.0));
                break;

            case 'chart':
                $shapes .= '<p:graphicFrame><p:nvGraphicFramePr><p:cNvPr id="' . $this->nextId() . '" name="Gráfico"/><p:cNvGraphicFramePr/><p:nvPr/></p:nvGraphicFramePr>'
                    . '<p:xfrm><a:off x="' . $in(0.6) . '" y="' . $bodyY . '"/><a:ext cx="' . $in(11.0) . '" cy="' . $bodyH . '"/></p:xfrm>'
                    . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" r:id="rIdChart"/></a:graphicData></a:graphic></p:graphicFrame>';
                break;

            case 'kpis':
                $count = max(1, count($slide['items']));
                $gap = $in(0.3);
                $width = (int) (($in(11.0) - $gap * ($count - 1)) / $count);
                foreach ($slide['items'] as $i => $item) {
                    $x = $in(0.6) + $i * ($width + $gap);
                    $shapes .= $this->rect($x, $bodyY + $in(0.4), $width, $in(2.6), $t['zebra']);
                    $shapes .= $this->rect($x, $bodyY + $in(0.4), $in(0.08), $in(2.6), $t['primary']);
                    $lines = [[$item['value'], $count > 3 ? 3000 : 3600, true, $t['primary']], [$item['label'], 1600, true, $t['text']]];
                    if ($item['detail'] !== '') {
                        $lines[] = [$item['detail'], 1300, false, $t['secondary']];
                    }
                    $shapes .= $this->text($x + $in(0.3), $bodyY + $in(0.6), $width - $in(0.45), $in(2.2), $lines, 'l', 'ctr');
                }
                break;
        }
        if ($bottom !== '') {
            $shapes .= $this->text($in(0.6), $bodyY + $bodyH + $in(0.05), $in(11.0), $in(0.4), [[$bottom, 1200, false, $t['secondary']]], 'l', 't');
        }

        return $this->slideXml($shapes . $this->footer($number, $total, $logo));
    }

    private function footer(int $number, int $total, ?array $logo): string
    {
        $in = static fn(float $inches): int => (int) round($inches * self::IN);
        $muted = $this->theme['secondary'];
        $xml = $this->text($in(0.6), $in(7.0), $in(8.0), $in(0.35), [[$this->company, 1000, false, $muted]], 'l', 'ctr')
            . $this->text($in(10.6), $in(7.0), $in(2.1), $in(0.35), [[$number . ' / ' . $total, 1000, false, $muted]], 'r', 'ctr');
        if ($logo !== null) {
            $xml .= $this->picture($logo, self::W - $in(0.5) - $logo['cx'], $in(0.4));
        }

        return $xml;
    }

    private function slideXml(string $shapes): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sld ' . self::NS . '><p:cSld><p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . $shapes . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
    }

    private function nextId(): int
    {
        return $this->shapeId++;
    }

    private function rect(int $x, int $y, int $cx, int $cy, string $color): string
    {
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $this->nextId() . '" name="Forma"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
            . '<a:solidFill><a:srgbClr val="' . OoxmlPackage::hex($color) . '"/></a:solidFill><a:ln><a:noFill/></a:ln></p:spPr></p:sp>';
    }

    /** @param list<array{0:string,1:int,2:bool,3:string}> $lines [texto, tamaño×100, negrita, color] */
    private function text(int $x, int $y, int $cx, int $cy, array $lines, string $align, string $anchor): string
    {
        $paragraphs = '';
        foreach ($lines as [$text, $size, $bold, $color]) {
            $paragraphs .= '<a:p><a:pPr algn="' . $align . '"/>' . $this->run($text, $size, $bold, $color) . '</a:p>';
        }

        return $this->textBody($x, $y, $cx, $cy, $paragraphs, $anchor);
    }

    private function textBody(int $x, int $y, int $cx, int $cy, string $paragraphs, string $anchor): string
    {
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $this->nextId() . '" name="Texto"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
            . '<p:txBody><a:bodyPr wrap="square" lIns="0" tIns="0" rIns="0" bIns="0" anchor="' . $anchor . '"><a:normAutofit/></a:bodyPr><a:lstStyle/>' . $paragraphs . '</p:txBody></p:sp>';
    }

    private function run(string $text, int $size, bool $bold, string $color): string
    {
        return '<a:r><a:rPr lang="es-ES" sz="' . $size . '"' . ($bold ? ' b="1"' : '') . ' dirty="0"><a:solidFill><a:srgbClr val="' . OoxmlPackage::hex($color) . '"/></a:solidFill>'
            . '<a:latin typeface="' . $this->font . '"/></a:rPr><a:t>' . OoxmlPackage::xml($text) . '</a:t></a:r>';
    }

    /** Texto con `**negrita**` en tramos. */
    private function runs(string $text, int $size, string $color): string
    {
        $xml = '';
        foreach (preg_split('/(\*\*[^*]+\*\*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $bold = str_starts_with($part, '**') && str_ends_with($part, '**') && strlen($part) > 4;
            $xml .= $this->run($bold ? substr($part, 2, -2) : $part, $size, $bold, $color);
        }

        return $xml;
    }

    private function picture(array $logo, int $x, int $y): string
    {
        return '<p:pic><p:nvPicPr><p:cNvPr id="' . $this->nextId() . '" name="Logo"/><p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
            . '<p:blipFill><a:blip r:embed="rIdLogo"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $logo['cx'] . '" cy="' . $logo['cy'] . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
    }

    private function table(array $header, array $rows, int $x, int $y, int $cx): string
    {
        $t = $this->theme;
        $cols = max(1, count($header));
        $colWidth = (int) floor($cx / $cols);
        $rowHeight = count($rows) > 8 ? 320040 : 400050;
        $size = count($rows) > 8 ? 1200 : 1400;
        $cell = function (string $text, bool $head, ?string $fill, bool $right) use ($size, $t): string {
            return '<a:tc><a:txBody><a:bodyPr/><a:lstStyle/><a:p><a:pPr algn="' . ($right ? 'r' : 'l') . '"/>'
                . $this->run($text, $size, $head, $head ? $t['headerText'] : $t['text']) . '</a:p></a:txBody>'
                . '<a:tcPr marL="91440" marR="91440" marT="45720" marB="45720" anchor="ctr">'
                . '<a:lnL w="0"><a:noFill/></a:lnL><a:lnR w="0"><a:noFill/></a:lnR><a:lnT w="0"><a:noFill/></a:lnT>'
                . '<a:lnB w="6350"><a:solidFill><a:srgbClr val="' . OoxmlPackage::hex(OfficeTheme::mix($t['zebra'], '#9CA3AF', 0.35)) . '"/></a:solidFill></a:lnB>'
                . ($fill !== null ? '<a:solidFill><a:srgbClr val="' . OoxmlPackage::hex($fill) . '"/></a:solidFill>' : '<a:noFill/>')
                . '</a:tcPr></a:tc>';
        };
        $numeric = static fn(string $text): bool => preg_match('/^[-+]?[\d.,\s]+(\s?[€$%]|\s?[A-Z]{3})?$/u', $text) === 1;

        $xml = '<a:tbl><a:tblPr firstRow="1" bandRow="1"/><a:tblGrid>' . str_repeat('<a:gridCol w="' . $colWidth . '"/>', $cols) . '</a:tblGrid>';
        $xml .= '<a:tr h="' . $rowHeight . '">';
        foreach ($header as $c => $label) {
            $right = $rows !== [] && array_reduce($rows, static fn(bool $all, array $row): bool => $all && ($row[$c] === '' || $numeric($row[$c])), true);
            $xml .= $cell((string) $label, true, $t['primary'], $right);
        }
        $xml .= '</a:tr>';
        foreach ($rows as $r => $row) {
            $xml .= '<a:tr h="' . $rowHeight . '">';
            foreach ($row as $value) {
                $xml .= $cell((string) $value, false, $r % 2 === 1 ? $t['zebra'] : null, $numeric((string) $value));
            }
            $xml .= '</a:tr>';
        }
        $xml .= '</a:tbl>';

        return '<p:graphicFrame><p:nvGraphicFramePr><p:cNvPr id="' . $this->nextId() . '" name="Tabla"/><p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr><p:nvPr/></p:nvGraphicFramePr>'
            . '<p:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . ($colWidth * $cols) . '" cy="' . ($rowHeight * (count($rows) + 1)) . '"/></p:xfrm>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table">' . $xml . '</a:graphicData></a:graphic></p:graphicFrame>';
    }

    // ------------------------------------------------------------------
    // Gráficos
    // ------------------------------------------------------------------

    /** Paleta de series: primario, secundario y tonos derivados (siempre de la marca). */
    public static function palette(array $theme): array
    {
        return [$theme['primary'], $theme['secondary'], OfficeTheme::mix($theme['primary'], '#FFFFFF', 0.45), OfficeTheme::mix($theme['secondary'], '#FFFFFF', 0.45),
            OfficeTheme::mix($theme['primary'], '#000000', 0.35), OfficeTheme::mix($theme['secondary'], '#000000', 0.35)];
    }

    private function chartXml(array $chart): string
    {
        $type = $chart['type'];
        $categories = $chart['categories'];
        $palette = self::palette($this->theme);
        $n = count($categories);
        $lastRow = $n + 1;
        $catCache = '<c:strCache><c:ptCount val="' . $n . '"/>';
        foreach ($categories as $i => $category) {
            $catCache .= '<c:pt idx="' . $i . '"><c:v>' . OoxmlPackage::xml((string) $category) . '</c:v></c:pt>';
        }
        $catCache .= '</c:strCache>';
        $fill = static fn(string $color): string => '<a:solidFill><a:srgbClr val="' . OoxmlPackage::hex($color) . '"/></a:solidFill>';

        $series = '';
        foreach ($chart['series'] as $s => $serie) {
            $col = chr(66 + $s);
            $values = '<c:numCache><c:formatCode>General</c:formatCode><c:ptCount val="' . $n . '"/>';
            foreach ($serie['values'] as $i => $value) {
                $values .= '<c:pt idx="' . $i . '"><c:v>' . OoxmlPackage::number($value) . '</c:v></c:pt>';
            }
            $values .= '</c:numCache>';
            $color = $palette[$s % count($palette)];
            $series .= '<c:ser><c:idx val="' . $s . '"/><c:order val="' . $s . '"/>'
                . '<c:tx><c:strRef><c:f>Hoja1!$' . $col . '$1</c:f><c:strCache><c:ptCount val="1"/><c:pt idx="0"><c:v>' . OoxmlPackage::xml((string) $serie['name']) . '</c:v></c:pt></c:strCache></c:strRef></c:tx>';
            if ($type === 'line') {
                $series .= '<c:spPr><a:ln w="38100" cap="rnd">' . $fill($color) . '<a:round/></a:ln></c:spPr><c:marker><c:symbol val="circle"/><c:size val="7"/><c:spPr>' . $fill($color) . '</c:spPr></c:marker>';
            } elseif ($type === 'pie') {
                $series .= '<c:spPr><a:ln w="12700"><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></a:ln></c:spPr>';
                foreach (array_keys($categories) as $i) {
                    $series .= '<c:dPt><c:idx val="' . $i . '"/><c:bubble3D val="0"/><c:spPr>' . $fill($palette[$i % count($palette)]) . '<a:ln w="12700"><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></a:ln></c:spPr></c:dPt>';
                }
                $series .= '<c:dLbls><c:numFmt formatCode="0%" sourceLinked="0"/><c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr><c:showLegendKey val="0"/><c:showVal val="0"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="1"/><c:showBubbleSize val="0"/></c:dLbls>';
            } else {
                $series .= '<c:spPr>' . $fill($color) . '</c:spPr><c:invertIfNegative val="0"/>';
            }
            $series .= '<c:cat><c:strRef><c:f>Hoja1!$A$2:$A$' . $lastRow . '</c:f>' . $catCache . '</c:strRef></c:cat>'
                . '<c:val><c:numRef><c:f>Hoja1!$' . $col . '$2:$' . $col . '$' . $lastRow . '</c:f>' . $values . '</c:numRef></c:val>'
                . ($type === 'line' ? '<c:smooth val="0"/>' : '') . '</c:ser>';
        }

        $axes = '<c:axId val="50010"/><c:axId val="50020"/>';
        $unit = (string) ($chart['unit'] ?? '');
        $valFormat = '#,##0' . ($unit !== '' ? '\ "' . str_replace('"', '', $unit) . '"' : '');
        $textColor = OoxmlPackage::hex($this->theme['text']);
        $axisText = '<c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1200"><a:solidFill><a:srgbClr val="' . $textColor . '"/></a:solidFill><a:latin typeface="' . $this->font . '"/></a:defRPr></a:pPr><a:endParaRPr lang="es-ES"/></a:p></c:txPr>';
        $axisLine = '<c:spPr><a:ln w="9525"><a:solidFill><a:srgbClr val="D1D5DB"/></a:solidFill></a:ln></c:spPr>';

        if ($type === 'pie') {
            $plot = '<c:pieChart><c:varyColors val="1"/>' . $series . '<c:firstSliceAng val="0"/></c:pieChart>';
        } elseif ($type === 'line') {
            $plot = '<c:lineChart><c:grouping val="standard"/><c:varyColors val="0"/>' . $series . '<c:marker val="1"/>' . $axes . '</c:lineChart>';
        } else {
            $plot = '<c:barChart><c:barDir val="' . ($type === 'bar' ? 'bar' : 'col') . '"/><c:grouping val="clustered"/><c:varyColors val="0"/>' . $series
                . '<c:gapWidth val="70"/>' . $axes . '</c:barChart>';
        }
        if ($type !== 'pie') {
            $catPos = $type === 'bar' ? 'l' : 'b';
            $valPos = $type === 'bar' ? 'b' : 'l';
            $plot .= '<c:catAx><c:axId val="50010"/><c:scaling><c:orientation val="' . ($type === 'bar' ? 'maxMin' : 'minMax') . '"/></c:scaling><c:delete val="0"/><c:axPos val="' . $catPos . '"/>'
                . '<c:numFmt formatCode="General" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>' . $axisLine . $axisText
                . '<c:crossAx val="50020"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/><c:noMultiLvlLbl val="0"/></c:catAx>'
                . '<c:valAx><c:axId val="50020"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="' . $valPos . '"/>'
                . '<c:majorGridlines><c:spPr><a:ln w="6350"><a:solidFill><a:srgbClr val="E5E7EB"/></a:solidFill></a:ln></c:spPr></c:majorGridlines>'
                . '<c:numFmt formatCode="' . OoxmlPackage::xml($valFormat) . '" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>'
                . '<c:spPr><a:ln><a:noFill/></a:ln></c:spPr>' . $axisText . '<c:crossAx val="50010"/><c:crosses val="' . ($type === 'bar' ? 'max' : 'autoZero') . '"/><c:crossBetween val="between"/></c:valAx>';
        }
        $legend = count($chart['series']) > 1 || $type === 'pie'
            ? '<c:legend><c:legendPos val="b"/><c:overlay val="0"/>' . $axisText . '</c:legend>'
            : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<c:lang val="es-ES"/><c:roundedCorners val="0"/><c:chart><c:autoTitleDeleted val="1"/><c:plotArea><c:layout/>' . $plot . '</c:plotArea>'
            . $legend . '<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/></c:chart>'
            . '<c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr></c:chartSpace>';
    }

    // ------------------------------------------------------------------
    // Partes fijas
    // ------------------------------------------------------------------

    private function themeXml(): string
    {
        $t = $this->theme;
        $hex = static fn(string $color): string => OoxmlPackage::hex($color);
        $palette = self::palette($t);
        $scheme = '<a:dk1><a:srgbClr val="' . $hex($t['text']) . '"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>'
            . '<a:dk2><a:srgbClr val="' . $hex($t['primary']) . '"/></a:dk2><a:lt2><a:srgbClr val="' . $hex($t['zebra']) . '"/></a:lt2>';
        foreach ($palette as $i => $color) {
            $scheme .= '<a:accent' . ($i + 1) . '><a:srgbClr val="' . $hex($color) . '"/></a:accent' . ($i + 1) . '>';
        }
        $scheme .= '<a:hlink><a:srgbClr val="' . $hex($t['primary']) . '"/></a:hlink><a:folHlink><a:srgbClr val="' . $hex($t['secondary']) . '"/></a:folHlink>';
        $font = '<a:latin typeface="' . $this->font . '"/><a:ea typeface=""/><a:cs typeface=""/>';
        $fill = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
        $line = static fn(int $w): string => '<a:ln w="' . $w . '" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/><a:miter lim="800000"/></a:ln>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="PDF Studio"><a:themeElements>'
            . '<a:clrScheme name="PDF Studio">' . $scheme . '</a:clrScheme>'
            . '<a:fontScheme name="PDF Studio"><a:majorFont>' . $font . '</a:majorFont><a:minorFont>' . $font . '</a:minorFont></a:fontScheme>'
            . '<a:fmtScheme name="PDF Studio"><a:fillStyleLst>' . $fill . $fill . $fill . '</a:fillStyleLst>'
            . '<a:lnStyleLst>' . $line(6350) . $line(12700) . $line(19050) . '</a:lnStyleLst>'
            . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            . '<a:bgFillStyleLst>' . $fill . $fill . $fill . '</a:bgFillStyleLst></a:fmtScheme>'
            . '</a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>';
    }

    private static function master(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldMaster ' . self::NS . '><p:cSld><p:bg><p:bgPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill><a:effectLst/></p:bgPr></p:bg><p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/></p:spTree></p:cSld>'
            . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
            . '<p:txStyles><p:titleStyle><a:lvl1pPr><a:defRPr sz="2800"/></a:lvl1pPr></p:titleStyle><p:bodyStyle><a:lvl1pPr><a:defRPr sz="2000"/></a:lvl1pPr></p:bodyStyle><p:otherStyle><a:lvl1pPr><a:defRPr sz="1400"/></a:lvl1pPr></p:otherStyle></p:txStyles>'
            . '</p:sldMaster>';
    }

    private static function layout(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldLayout ' . self::NS . ' type="blank" preserve="1"><p:cSld name="En blanco"><p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/></p:spTree></p:cSld>'
            . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>';
    }

    private static function presentation(int $slides): string
    {
        $ids = '';
        for ($i = 1; $i <= $slides; $i++) {
            $ids .= '<p:sldId id="' . (255 + $i) . '" r:id="rIdSlide' . $i . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentation ' . self::NS . ' saveSubsetFonts="1">'
            . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rIdMaster"/></p:sldMasterIdLst><p:sldIdLst>' . $ids . '</p:sldIdLst>'
            . '<p:sldSz cx="' . self::W . '" cy="' . self::H . '"/><p:notesSz cx="6858000" cy="9144000"/></p:presentation>';
    }

    private static function presentationRels(int $slides): string
    {
        $xml = '<Relationship Id="rIdMaster" Type="' . self::REL . 'slideMaster" Target="slideMasters/slideMaster1.xml"/>'
            . '<Relationship Id="rIdTheme" Type="' . self::REL . 'theme" Target="theme/theme1.xml"/>'
            . '<Relationship Id="rIdPresProps" Type="' . self::REL . 'presProps" Target="presProps.xml"/>'
            . '<Relationship Id="rIdViewProps" Type="' . self::REL . 'viewProps" Target="viewProps.xml"/>'
            . '<Relationship Id="rIdTableStyles" Type="' . self::REL . 'tableStyles" Target="tableStyles.xml"/>';
        for ($i = 1; $i <= $slides; $i++) {
            $xml .= '<Relationship Id="rIdSlide' . $i . '" Type="' . self::REL . 'slide" Target="slides/slide' . $i . '.xml"/>';
        }

        return self::rels($xml);
    }

    private static function rels(string $relationships): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationships . '</Relationships>';
    }

    private static function contentTypes(int $slides, int $charts, ?array $logo): string
    {
        $pml = 'application/vnd.openxmlformats-officedocument.presentationml.';
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . ($logo !== null ? '<Default Extension="' . $logo['ext'] . '" ContentType="' . $logo['mime'] . '"/>' : '')
            . '<Override PartName="/ppt/presentation.xml" ContentType="' . $pml . 'presentation.main+xml"/>'
            . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="' . $pml . 'slideMaster+xml"/>'
            . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="' . $pml . 'slideLayout+xml"/>'
            . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            . '<Override PartName="/ppt/presProps.xml" ContentType="' . $pml . 'presProps+xml"/>'
            . '<Override PartName="/ppt/viewProps.xml" ContentType="' . $pml . 'viewProps+xml"/>'
            . '<Override PartName="/ppt/tableStyles.xml" ContentType="' . $pml . 'tableStyles+xml"/>';
        for ($i = 1; $i <= $slides; $i++) {
            $xml .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="' . $pml . 'slide+xml"/>';
        }
        for ($i = 1; $i <= $charts; $i++) {
            $xml .= '<Override PartName="/ppt/charts/chart' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>';
        }

        return $xml . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
    }
}
