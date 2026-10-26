<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

use RuntimeException;
use ZipArchive;

/**
 * DOCX (Office Open XML) mínimo y propio de un documento `beply.document/v1`,
 * sin dependencias: ZipArchive + XML escrito a mano.
 *
 * Partes: [Content_Types].xml, _rels/.rels, docProps/{core,app}.xml,
 * word/{document,styles,numbering,settings,footer1}.xml, sus relaciones y el
 * logo en word/media. Estilos con la fuente, el tamaño y los colores de la
 * marca (Title, Heading1-3, Quote, BeplyNote, BeplyTable); listas con
 * numbering.xml (cada lista numerada reinicia en 1); pie con texto y número
 * de página. Abre en Word, LibreOffice y Google Docs.
 */
final class DocumentDocxWriter
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const TWIP_PER_MM = 56.6929;
    private const EMU_PER_MM = 36000;

    public static function available(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /** Bytes del .docx. */
    public static function render(array $spec, array $branding, ?\DateTimeInterface $now = null): string
    {
        if (false === self::available()) {
            throw new RuntimeException('docx-unavailable');
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $logo = self::logo($branding);
        $numbered = 0;
        $body = self::letterhead($spec, $branding, $logo) . self::opening($spec, $branding);
        foreach ((array) ($spec['blocks'] ?? []) as $block) {
            $body .= self::block($block, $branding, $numbered);
        }

        [$w, $h] = DocumentHtmlRenderer::paperSize($branding);
        $m = $branding['margins'];
        $landscape = ($branding['orientation'] ?? 'portrait') === 'landscape' ? ' w:orient="landscape"' : '';
        $sect = '<w:sectPr><w:footerReference w:type="default" r:id="rIdFooter1"/>'
            . '<w:pgSz w:w="' . self::twips($w) . '" w:h="' . self::twips($h) . '"' . $landscape . '/>'
            . '<w:pgMar w:top="' . self::twips($m['top']) . '" w:right="' . self::twips($m['right']) . '" w:bottom="' . self::twips($m['bottom']) . '" w:left="' . self::twips($m['left']) . '" w:header="567" w:footer="425" w:gutter="0"/>'
            . '</w:sectPr>';

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="' . self::W_NS . '" xmlns:r="' . self::R_NS . '"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<w:body>' . $body . $sect . '</w:body></w:document>';

        $documentRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rIdNumbering" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
            . '<Relationship Id="rIdSettings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
            . '<Relationship Id="rIdFooter1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
            . ($logo !== null ? '<Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.' . $logo['ext'] . '"/>' : '')
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'bdocx');
        if ($tmp === false) {
            throw new RuntimeException('docx-temp-failed');
        }
        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('docx-open-failed');
            }
            $zip->addFromString('[Content_Types].xml', self::contentTypes($logo));
            $zip->addFromString('_rels/.rels', self::rootRels());
            $zip->addFromString('docProps/core.xml', self::coreProps((string) ($spec['title'] ?? 'Documento'), $now, (string) ($branding['company']['name'] ?? '')));
            $zip->addFromString('docProps/app.xml', self::appProps((string) ($branding['company']['name'] ?? '')));
            $zip->addFromString('word/document.xml', $document);
            $zip->addFromString('word/_rels/document.xml.rels', $documentRels);
            $zip->addFromString('word/styles.xml', self::styles($branding));
            $zip->addFromString('word/numbering.xml', self::numbering($numbered));
            $zip->addFromString('word/settings.xml', self::settings());
            $zip->addFromString('word/footer1.xml', self::footer($branding));
            if ($logo !== null) {
                $zip->addFromString('word/media/logo.' . $logo['ext'], $logo['bytes']);
            }
            $zip->close();
            $bytes = file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('docx-write-failed');
        }

        return $bytes;
    }

    // ------------------------------------------------------------------
    // Cuerpo
    // ------------------------------------------------------------------

    private static function letterhead(array $spec, array $branding, ?array $logo): string
    {
        $meta = (array) ($spec['meta'] ?? []);
        $company = (array) ($branding['company'] ?? []);
        $showCompany = ($meta['show_company'] ?? true) !== false && ($company['name'] ?? '') !== '';
        if ($logo === null && false === $showCompany) {
            return '';
        }

        $logoCell = '<w:p>' . ($logo !== null ? '<w:r>' . self::drawing($logo) . '</w:r>' : '') . '</w:p>';
        $companyCell = '';
        if ($showCompany) {
            $companyCell .= '<w:p><w:pPr><w:spacing w:after="0"/><w:jc w:val="right"/></w:pPr>' . self::run((string) $company['name'], ['bold' => true]) . '</w:p>';
            $lines = array_filter([
                (string) ($company['cifnif'] ?? ''),
                (string) ($company['address'] ?? ''),
                implode(' · ', array_filter([(string) ($company['phone'] ?? ''), (string) ($company['email'] ?? ''), (string) ($company['web'] ?? '')])),
            ]);
            foreach ($lines as $line) {
                $companyCell .= '<w:p><w:pPr><w:spacing w:after="0"/><w:jc w:val="right"/></w:pPr>'
                    . self::run($line, ['color' => DocumentPdfColor::hex($branding['secondary']), 'size' => max(14, (int) round(($branding['fontSize'] - 1.5) * 2))]) . '</w:p>';
            }
        } else {
            $companyCell = '<w:p/>';
        }

        $border = '<w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/>'
            . '<w:bottom w:val="single" w:sz="12" w:space="0" w:color="' . DocumentPdfColor::hex($branding['primary']) . '"/>'
            . '<w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>';

        return '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>' . $border . '<w:tblLayout w:type="fixed"/>'
            . '<w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/><w:bottom w:w="120" w:type="dxa"/></w:tblCellMar></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="4500"/><w:gridCol w:w="4500"/></w:tblGrid><w:tr>'
            . '<w:tc><w:tcPr><w:tcW w:w="2500" w:type="pct"/><w:vAlign w:val="bottom"/></w:tcPr>' . $logoCell . '</w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="2500" w:type="pct"/><w:vAlign w:val="bottom"/></w:tcPr>' . $companyCell . '</w:tc>'
            . '</w:tr></w:tbl><w:p><w:pPr><w:spacing w:after="240"/></w:pPr></w:p>';
    }

    private static function opening(array $spec, array $branding): string
    {
        $meta = (array) ($spec['meta'] ?? []);
        $xml = '';
        $placeDate = trim(implode(', ', array_filter([
            (string) ($meta['place'] ?? ''),
            isset($meta['date']) ? DocumentHtmlRenderer::formatDate((string) $meta['date']) : '',
        ])));
        if ($placeDate !== '') {
            $xml .= '<w:p><w:pPr><w:spacing w:after="240"/><w:jc w:val="right"/></w:pPr>' . self::run($placeDate) . '</w:p>';
        }
        if (isset($meta['recipient'])) {
            $lines = (array) ($meta['recipient']['lines'] ?? []);
            if (($meta['recipient']['name'] ?? '') !== '') {
                $xml .= '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr>' . self::run($meta['recipient']['name'], ['bold' => true]) . '</w:p>';
            }
            foreach ($lines as $line) {
                $xml .= '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr>' . self::run((string) $line) . '</w:p>';
            }
            $xml .= '<w:p><w:pPr><w:spacing w:after="120"/></w:pPr></w:p>';
        }
        foreach (['reference' => 'Ref.: ', 'subject' => 'Asunto: '] as $key => $label) {
            if (isset($meta[$key])) {
                $xml .= '<w:p><w:pPr><w:spacing w:after="' . ($key === 'subject' ? '240' : '60') . '"/></w:pPr>'
                    . self::run($label, ['bold' => true, 'color' => DocumentPdfColor::hex($branding['secondary'])]) . self::run($meta[$key]) . '</w:p>';
            }
        }
        if (($meta['show_title'] ?? true) !== false) {
            $xml .= '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr>' . self::run((string) ($spec['title'] ?? '')) . '</w:p>';
            if (isset($meta['subtitle'])) {
                $xml .= '<w:p><w:pPr><w:pStyle w:val="Subtitle"/></w:pPr>' . self::run($meta['subtitle']) . '</w:p>';
            }
        }

        return $xml;
    }

    private static function block(array $block, array $branding, int &$numbered): string
    {
        switch ($block['type'] ?? '') {
            case 'heading':
                $level = max(1, min(3, (int) ($block['level'] ?? 1)));
                return '<w:p><w:pPr><w:pStyle w:val="Heading' . $level . '"/></w:pPr>' . self::inline((string) $block['text']) . '</w:p>';

            case 'paragraph':
                $jc = ['center' => 'center', 'right' => 'right', 'justify' => 'both'][$block['align'] ?? 'left'] ?? null;
                if ($jc !== 'both') {
                    return '<w:p>' . ($jc !== null ? '<w:pPr><w:jc w:val="' . $jc . '"/></w:pPr>' : '') . self::inline((string) $block['text']) . '</w:p>';
                }
                // Justificado: cada línea forzada es su propio párrafo (Word estira la línea que acaba en salto).
                $lines = explode("\n", (string) $block['text']);
                $xml = '';
                foreach ($lines as $i => $line) {
                    $spacing = $i < count($lines) - 1 ? '<w:spacing w:after="0"/>' : '';
                    $xml .= '<w:p><w:pPr>' . $spacing . '<w:jc w:val="both"/></w:pPr>' . self::inline($line) . '</w:p>';
                }
                return $xml;

            case 'list':
                if (($block['style'] ?? 'bullet') === 'number') {
                    $numbered++;
                    $numId = 1 + $numbered;
                } else {
                    $numId = 1;
                }
                $xml = '';
                foreach ((array) $block['items'] as $item) {
                    $xml .= '<w:p><w:pPr><w:pStyle w:val="ListParagraph"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . $numId . '"/></w:numPr>'
                        . '<w:spacing w:after="60"/></w:pPr>' . self::inline((string) $item) . '</w:p>';
                }
                return $xml . '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr></w:p>';

            case 'table':
                return self::table($block, $branding);

            case 'quote':
                return '<w:p><w:pPr><w:pStyle w:val="Quote"/></w:pPr>' . self::inline((string) $block['text']) . '</w:p>';

            case 'note':
                $color = ['warning' => 'B7791F', 'success' => '2F855A'][$block['tone'] ?? 'info'] ?? DocumentPdfColor::hex($branding['primary']);
                $fill = ['warning' => 'FFF8E6', 'success' => 'EEF8F1'][$block['tone'] ?? 'info'] ?? 'F5F7FA';
                $pPr = static fn(string $keep, string $spacing): string => '<w:pPr><w:pStyle w:val="BeplyNote"/>' . $keep
                    . '<w:pBdr><w:left w:val="single" w:sz="24" w:space="8" w:color="' . $color . '"/></w:pBdr>'
                    . '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>' . $spacing . '</w:pPr>';
                $xml = '';
                if (isset($block['title'])) {
                    $xml .= '<w:p>' . $pPr('<w:keepNext/>', '') . self::run($block['title'], ['bold' => true]) . '</w:p>';
                }
                return $xml . '<w:p>' . $pPr('', '<w:spacing w:after="200"/>') . self::inline((string) $block['text']) . '</w:p>';

            case 'signature':
                $xml = '<w:p><w:pPr><w:spacing w:before="480" w:after="0"/></w:pPr></w:p>';
                if (isset($block['place_date'])) {
                    $xml .= '<w:p><w:pPr><w:spacing w:after="720"/></w:pPr>' . self::run($block['place_date']) . '</w:p>';
                } else {
                    $xml .= '<w:p><w:pPr><w:spacing w:after="600"/></w:pPr></w:p>';
                }
                $xml .= '<w:p><w:pPr><w:pBdr><w:top w:val="single" w:sz="6" w:space="4" w:color="' . DocumentPdfColor::hex($branding['text']) . '"/></w:pBdr>'
                    . '<w:spacing w:after="0"/><w:ind w:right="' . self::twips(100) . '"/></w:pPr>'
                    . (isset($block['name']) ? self::run($block['name'], ['bold' => true]) : '') . '</w:p>';
                if (isset($block['role'])) {
                    $xml .= '<w:p>' . self::run($block['role']) . '</w:p>';
                }
                return $xml;

            case 'spacer':
                $after = ['sm' => 170, 'md' => 400, 'lg' => 800][$block['size'] ?? 'md'] ?? 400;
                return '<w:p><w:pPr><w:spacing w:after="' . $after . '"/></w:pPr></w:p>';

            case 'page_break':
                return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        }

        return '';
    }

    private static function table(array $block, array $branding): string
    {
        $header = (array) ($block['header'] ?? []);
        $rows = (array) ($block['rows'] ?? []);
        $count = max(count($header), count($rows[0] ?? []));
        if ($count === 0) {
            return '';
        }
        $primary = DocumentPdfColor::hex($branding['primary']);
        $line = 'D5DBE3';
        $numeric = DocumentHtmlRenderer::numericColumns($block);

        $xml = '';
        if (isset($block['caption'])) {
            $xml .= '<w:p><w:pPr><w:keepNext/><w:spacing w:before="120" w:after="60"/></w:pPr>'
                . self::run($block['caption'], ['bold' => true, 'color' => DocumentPdfColor::hex($branding['secondary'])]) . '</w:p>';
        }
        $width = (int) floor(5000 / $count);
        $xml .= '<w:tbl><w:tblPr><w:tblStyle w:val="BeplyTable"/><w:tblW w:w="5000" w:type="pct"/>'
            . '<w:tblBorders><w:top w:val="single" w:sz="4" w:color="' . $line . '"/><w:left w:val="nil"/><w:bottom w:val="single" w:sz="4" w:color="' . $line . '"/>'
            . '<w:right w:val="nil"/><w:insideH w:val="single" w:sz="4" w:color="' . $line . '"/><w:insideV w:val="nil"/></w:tblBorders>'
            . '<w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar>'
            . '</w:tblPr><w:tblGrid>' . str_repeat('<w:gridCol w:w="' . (int) floor(9000 / $count) . '"/>', $count) . '</w:tblGrid>';

        $cell = static function (string $content, bool $isHeader, bool $right, int $width, ?string $fill) use ($primary): string {
            $tcPr = '<w:tcPr><w:tcW w:w="' . $width . '" w:type="pct"/>' . ($fill !== null ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>' : '') . '</w:tcPr>';
            $pPr = '<w:pPr><w:spacing w:after="0"/>' . ($right ? '<w:jc w:val="right"/>' : '') . '</w:pPr>';
            $runs = $isHeader ? self::run(DocumentInline::plain($content), ['bold' => true, 'color' => 'FFFFFF']) : self::inline($content);

            return '<w:tc>' . $tcPr . '<w:p>' . $pPr . $runs . '</w:p></w:tc>';
        };

        if ($header !== []) {
            $xml .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
            for ($i = 0; $i < $count; $i++) {
                $xml .= $cell((string) ($header[$i] ?? ''), true, $numeric[$i], $width, $primary);
            }
            $xml .= '</w:tr>';
        }
        foreach ($rows as $r => $row) {
            $xml .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
            $row = array_values($row);
            for ($i = 0; $i < $count; $i++) {
                $xml .= $cell((string) ($row[$i] ?? ''), false, $numeric[$i], $width, $r % 2 === 1 ? 'F5F7FA' : null);
            }
            $xml .= '</w:tr>';
        }

        return $xml . '</w:tbl><w:p><w:pPr><w:spacing w:after="120"/></w:pPr></w:p>';
    }

    /** Tramos con negrita/cursiva y saltos de línea. */
    public static function inline(string $text): string
    {
        $xml = '';
        foreach (DocumentInline::runs($text) as $run) {
            $xml .= self::run($run['text'], ['bold' => $run['bold'], 'italic' => $run['italic']]);
        }

        return $xml;
    }

    /** @param array{bold?: bool, italic?: bool, color?: string, size?: int} $format */
    private static function run(string $text, array $format = []): string
    {
        $rPr = '';
        if ($format['bold'] ?? false) {
            $rPr .= '<w:b/>';
        }
        if ($format['italic'] ?? false) {
            $rPr .= '<w:i/>';
        }
        if (isset($format['color'])) {
            $rPr .= '<w:color w:val="' . $format['color'] . '"/>';
        }
        if (isset($format['size'])) {
            $rPr .= '<w:sz w:val="' . (int) $format['size'] . '"/><w:szCs w:val="' . (int) $format['size'] . '"/>';
        }
        $parts = explode("\n", $text);
        $content = '';
        foreach ($parts as $i => $part) {
            if ($i > 0) {
                $content .= '<w:br/>';
            }
            foreach (explode("\t", $part) as $j => $piece) {
                if ($j > 0) {
                    $content .= '<w:tab/>';
                }
                if ($piece !== '') {
                    $content .= '<w:t xml:space="preserve">' . self::xml($piece) . '</w:t>';
                }
            }
        }

        return '<w:r>' . ($rPr !== '' ? '<w:rPr>' . $rPr . '</w:rPr>' : '') . $content . '</w:r>';
    }

    private static function drawing(array $logo): string
    {
        $cx = (int) $logo['cx'];
        $cy = (int) $logo['cy'];

        return '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
            . '<wp:docPr id="1" name="Logo"/><wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic>'
            . '<pic:nvPicPr><pic:cNvPr id="0" name="logo.' . $logo['ext'] . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="rIdLogo"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing>';
    }

    /** @return array{bytes: string, ext: string, mime: string, cx: int, cy: int}|null */
    private static function logo(array $branding): ?array
    {
        $path = $branding['logo']['path'] ?? null;
        if (false === is_string($path) || false === DocumentBranding::isLogo(['path' => $path])) {
            return null;
        }
        $info = @getimagesize($path);
        $bytes = @file_get_contents($path);
        if (false === is_array($info) || $info[0] <= 0 || $info[1] <= 0 || false === is_string($bytes)) {
            return null;
        }
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpeg', 'image/gif' => 'gif'][$info['mime']] ?? null;
        if ($ext === null) {
            return null;
        }
        $scale = min(48 * self::EMU_PER_MM / $info[0], 20 * self::EMU_PER_MM / $info[1]);

        return ['bytes' => $bytes, 'ext' => $ext, 'mime' => $info['mime'], 'cx' => (int) round($info[0] * $scale), 'cy' => (int) round($info[1] * $scale)];
    }

    // ------------------------------------------------------------------
    // Partes fijas
    // ------------------------------------------------------------------

    private static function styles(array $branding): string
    {
        $font = self::xml($branding['font']['name']);
        $size = (int) round($branding['fontSize'] * 2);
        $text = DocumentPdfColor::hex($branding['text']);
        $primary = DocumentPdfColor::hex($branding['primary']);
        $secondary = DocumentPdfColor::hex($branding['secondary']);
        $heading = static fn(int $level, float $factor, int $before) => '<w:style w:type="paragraph" w:styleId="Heading' . $level . '"><w:name w:val="heading ' . $level . '"/>'
            . '<w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:uiPriority w:val="9"/><w:qFormat/>'
            . '<w:pPr><w:keepNext/><w:keepLines/><w:spacing w:before="' . $before . '" w:after="100"/><w:outlineLvl w:val="' . ($level - 1) . '"/></w:pPr>'
            . '<w:rPr><w:b/><w:bCs/><w:color w:val="' . $primary . '"/><w:sz w:val="' . (int) round($size * $factor) . '"/><w:szCs w:val="' . (int) round($size * $factor) . '"/></w:rPr></w:style>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="' . self::W_NS . '">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '" w:eastAsia="' . $font . '" w:cs="' . $font . '"/>'
            . '<w:color w:val="' . $text . '"/><w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/><w:lang w:val="es-ES"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="160" w:line="300" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:spacing w:before="120" w:after="120"/></w:pPr><w:rPr><w:b/><w:color w:val="' . $primary . '"/><w:sz w:val="' . (int) round($size * 1.75) . '"/><w:szCs w:val="' . (int) round($size * 1.75) . '"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:spacing w:after="240"/></w:pPr><w:rPr><w:color w:val="' . $secondary . '"/><w:sz w:val="' . (int) round($size * 1.05) . '"/></w:rPr></w:style>'
            . $heading(1, 1.35, 360) . $heading(2, 1.15, 280) . $heading(3, 1.0, 220)
            . '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:qFormat/><w:pPr><w:ind w:left="720"/></w:pPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="Quote"/><w:basedOn w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:pBdr><w:left w:val="single" w:sz="24" w:space="8" w:color="' . $primary . '"/></w:pBdr><w:ind w:left="284"/></w:pPr>'
            . '<w:rPr><w:i/><w:color w:val="' . $secondary . '"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:customStyle="1" w:styleId="BeplyNote"><w:name w:val="Nota"/><w:basedOn w:val="Normal"/>'
            . '<w:pPr><w:spacing w:after="0"/><w:ind w:left="284" w:right="284"/></w:pPr></w:style>'
            . '<w:style w:type="table" w:customStyle="1" w:styleId="BeplyTable"><w:name w:val="Tabla"/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/></w:tblPr>'
            . '<w:rPr><w:sz w:val="' . max(14, $size - 2) . '"/><w:szCs w:val="' . max(14, $size - 2) . '"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Footer"><w:name w:val="footer"/><w:basedOn w:val="Normal"/>'
            . '<w:pPr><w:spacing w:after="0"/></w:pPr><w:rPr><w:color w:val="' . $secondary . '"/><w:sz w:val="15"/><w:szCs w:val="15"/></w:rPr></w:style>'
            . '</w:styles>';
    }

    private static function numbering(int $numbered): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="' . self::W_NS . '">'
            . '<w:abstractNum w:abstractNumId="0"><w:multiLevelType w:val="singleLevel"/><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/>'
            . '<w:lvlText w:val="•"/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl></w:abstractNum>'
            . '<w:abstractNum w:abstractNumId="1"><w:multiLevelType w:val="singleLevel"/><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/>'
            . '<w:lvlText w:val="%1."/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl></w:abstractNum>'
            . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>';
        // Una instancia por lista numerada: cada una empieza en 1.
        for ($i = 1; $i <= $numbered; $i++) {
            $xml .= '<w:num w:numId="' . (1 + $i) . '"><w:abstractNumId w:val="1"/><w:lvlOverride w:ilvl="0"><w:startOverride w:val="1"/></w:lvlOverride></w:num>';
        }

        return $xml . '</w:numbering>';
    }

    private static function footer(array $branding): string
    {
        $text = $branding['footerText'] !== '' ? self::run(str_replace("\n", ' · ', $branding['footerText'])) . self::run("\t") : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr xmlns:w="' . self::W_NS . '" xmlns:r="' . self::R_NS . '">'
            . '<w:p><w:pPr><w:pStyle w:val="Footer"/><w:pBdr><w:top w:val="single" w:sz="4" w:space="4" w:color="D5DBE3"/></w:pBdr>'
            . '<w:tabs><w:tab w:val="right" w:pos="9638"/></w:tabs></w:pPr>'
            . $text . ($text === '' ? self::run("\t") : '')
            . '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r>'
            . '<w:r><w:t xml:space="preserve"> / </w:t></w:r>'
            . '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> NUMPAGES </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r>'
            . '</w:p></w:ftr>';
    }

    private static function settings(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings xmlns:w="' . self::W_NS . '">'
            . '<w:defaultTabStop w:val="708"/><w:characterSpacingControl w:val="doNotCompress"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat>'
            . '</w:settings>';
    }

    private static function contentTypes(?array $logo): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . ($logo !== null ? '<Default Extension="' . $logo['ext'] . '" ContentType="' . $logo['mime'] . '"/>' : '')
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
            . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
            . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function coreProps(string $title, \DateTimeInterface $now, string $author = ''): string
    {
        $date = $now->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::xml($title) . '</dc:title><dc:creator>' . self::xml($author !== '' ? $author : 'Documento') . '</dc:creator><dc:language>es-ES</dc:language>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $date . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $date . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private static function appProps(string $author = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>' . self::xml($author !== '' ? $author : 'Documento') . '</Application></Properties>';
    }

    private static function twips(float $mm): int
    {
        return (int) round($mm * self::TWIP_PER_MM);
    }

    /** Texto seguro para XML 1.0 (sin caracteres de control no admitidos). */
    private static function xml(string $text): string
    {
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);

        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
