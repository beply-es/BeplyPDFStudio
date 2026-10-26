<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument;

/**
 * Escritor XLSX propio (SpreadsheetML con ZipArchive, como DocumentDocxWriter)
 * para las hojas de cálculo del chat (`beply.spreadsheet/v1`).
 *
 * Por qué propio y no PHP_XLSXWriter (vendor/mk-j del core): necesitamos el logo
 * (dibujo anclado), fórmulas SUBTOTAL con su valor cacheado, filas congeladas,
 * autofiltro y títulos de impresión con el estilo de PDF Studio, y que se pueda
 * probar sin FacturaScripts. Son ~300 líneas sin dependencias.
 *
 * Cada hoja: título (fila 1, color primario), subtítulo (fila 2), cabecera con el
 * color primario y texto de contraste, filas en cebra suave, formatos de número,
 * moneda, porcentaje y fecha españoles, fila de totales con SUBTOTAL (respeta el
 * autofiltro), nota final, anchos según el contenido, cabecera congelada y
 * repetida al imprimir, logo a la derecha de la última columna (primera hoja) y
 * pie con la empresa y «Página N de M».
 *
 * El texto siempre va como cadena en línea: nunca se convierte en fórmula.
 */
final class SpreadsheetXlsxWriter
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const SUBTOTAL = ['sum' => 109, 'average' => 101, 'count' => 103, 'min' => 105, 'max' => 104];
    private const CURRENCY_SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];

    /** @var array<string, int> */
    private array $numFmts = [];
    /** @var array<string, int> */
    private array $xfs = [];
    private array $theme;

    private function __construct(array $branding)
    {
        $this->theme = OfficeTheme::fromBranding($branding);
    }

    /** Bytes del .xlsx. */
    public static function render(array $spec, array $branding, ?\DateTimeInterface $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $writer = new self($branding);
        $logo = OoxmlPackage::logo($branding, 40, 10);
        $company = (string) ($branding['company']['name'] ?? '');

        $sheets = [];
        $definedNames = '';
        foreach (array_values((array) ($spec['sheets'] ?? [])) as $index => $sheet) {
            [$xml, $headerRow, $lastDataRow, $lastCol] = $writer->sheet($spec, $sheet, $index === 0 && $logo !== null ? $logo : null, $index === 0, $company);
            $sheets[] = $xml;
            $quoted = "'" . str_replace("'", "''", (string) $sheet['name']) . "'";
            $definedNames .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $index . '" hidden="1">' . OoxmlPackage::xml($quoted . '!$A$' . $headerRow . ':$' . $lastCol . '$' . $lastDataRow) . '</definedName>'
                . '<definedName name="_xlnm.Print_Titles" localSheetId="' . $index . '">' . OoxmlPackage::xml($quoted . '!$' . $headerRow . ':$' . $headerRow) . '</definedName>';
        }

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="' . self::MAIN_NS . '" xmlns:r="' . self::R_NS . '"><workbookPr/>'
            . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="28800" windowHeight="16000"/></bookViews><sheets>';
        foreach (array_values((array) $spec['sheets']) as $index => $sheet) {
            $workbook .= '<sheet name="' . OoxmlPackage::xml((string) $sheet['name']) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }
        $workbook .= '</sheets><definedNames>' . $definedNames . '</definedNames><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach (array_keys($sheets) as $index) {
            $workbookRels .= '<Relationship Id="rId' . ($index + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($index + 1) . '.xml"/>';
        }
        $workbookRels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

        $parts = [
            '[Content_Types].xml' => self::contentTypes(count($sheets), $logo),
            '_rels/.rels' => self::rootRels(),
            'xl/workbook.xml' => $workbook,
            'xl/_rels/workbook.xml.rels' => $workbookRels,
        ];
        foreach ($sheets as $index => $xml) {
            $parts['xl/worksheets/sheet' . ($index + 1) . '.xml'] = $xml;
        }
        $parts['xl/styles.xml'] = $writer->styles();
        if ($logo !== null) {
            $parts['xl/worksheets/_rels/sheet1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>';
            $parts['xl/drawings/drawing1.xml'] = self::drawing($logo, count($spec['sheets'][0]['columns']));
            $parts['xl/drawings/_rels/drawing1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.' . $logo['ext'] . '"/></Relationships>';
            $parts['xl/media/logo.' . $logo['ext']] = $logo['bytes'];
        }
        $parts['docProps/core.xml'] = OoxmlPackage::coreProps((string) ($spec['title'] ?? 'Hoja de cálculo'), $now, $company);
        $parts['docProps/app.xml'] = OoxmlPackage::appProps('Microsoft Excel');

        return OoxmlPackage::zip($parts, 'bxlsx');
    }

    /** Letra de columna de Excel (0 → A, 26 → AA). */
    public static function columnLetter(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26) . $letters;
        }

        return $letters;
    }

    // ------------------------------------------------------------------
    // Hoja
    // ------------------------------------------------------------------

    /** @return array{0: string, 1: int, 2: int, 3: string} */
    private function sheet(array $spec, array $sheet, ?array $logo, bool $selected, string $company): array
    {
        $columns = array_values($sheet['columns']);
        $rows = array_values($sheet['rows']);
        $lastCol = self::columnLetter(count($columns) - 1);
        $subtitle = (string) ($spec['subtitle'] ?? '');
        $headerRow = $subtitle !== '' ? 4 : 3;
        $firstData = $headerRow + 1;
        $lastData = $headerRow + count($rows);

        $xml = '<row r="1" ht="30" customHeight="1">' . $this->text('A1', (string) $spec['title'], $this->xf('title')) . '</row>';
        if ($subtitle !== '') {
            $xml .= '<row r="2">' . $this->text('A2', $subtitle, $this->xf('subtitle')) . '</row>';
        }

        $xml .= '<row r="' . $headerRow . '" ht="22" customHeight="1">';
        foreach ($columns as $c => $column) {
            $numeric = $column['type'] !== 'text';
            $xml .= $this->text(self::columnLetter($c) . $headerRow, $column['label'], $this->xf('header', $numeric ? 'right' : 'left'));
        }
        $xml .= '</row>';

        foreach ($rows as $r => $row) {
            $rowNumber = $firstData + $r;
            $zebra = $r % 2 === 1;
            $xml .= '<row r="' . $rowNumber . '">';
            foreach ($columns as $c => $column) {
                $ref = self::columnLetter($c) . $rowNumber;
                $style = $this->xf('cell', $column, $zebra);
                $xml .= $this->value($ref, $row[$c] ?? null, $column, $style);
            }
            $xml .= '</row>';
        }

        $nextRow = $lastData + 1;
        $hasTotals = $rows !== [] && array_filter($columns, static fn(array $c): bool => $c['total'] !== 'none') !== [];
        if ($hasTotals) {
            $xml .= '<row r="' . $nextRow . '">';
            foreach ($columns as $c => $column) {
                $ref = self::columnLetter($c) . $nextRow;
                if ($column['total'] === 'none') {
                    $xml .= $c === 0 ? $this->text($ref, 'Total', $this->xf('total-label')) : '<c r="' . $ref . '" s="' . $this->xf('total-label') . '"/>';
                    continue;
                }
                $letter = self::columnLetter($c);
                $cached = self::aggregate($column, array_column($rows, $c));
                $formula = 'SUBTOTAL(' . self::SUBTOTAL[$column['total']] . ',' . $letter . $firstData . ':' . $letter . $lastData . ')';
                $style = $this->xf('total', $column['total'] === 'count' ? ['type' => 'integer', 'decimals' => 0] : $column);
                $xml .= '<c r="' . $ref . '" s="' . $style . '"><f>' . $formula . '</f>' . ($cached === null ? '' : '<v>' . OoxmlPackage::number($cached) . '</v>') . '</c>';
            }
            $xml .= '</row>';
            $nextRow++;
        }
        if (isset($sheet['note']) && $sheet['note'] !== '') {
            $noteRow = $nextRow + 1;
            $xml .= '<row r="' . $noteRow . '">' . $this->text('A' . $noteRow, (string) $sheet['note'], $this->xf('note')) . '</row>';
        }

        $cols = '<cols>';
        foreach ($columns as $c => $column) {
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . sprintf('%.2F', self::width($column, array_column($rows, $c))) . '" customWidth="1"/>';
        }
        $cols .= '</cols>';

        $filterEnd = max($lastData, $headerRow);
        $landscape = count($columns) > 6 ? 'landscape' : 'portrait';
        $footer = '&amp;L' . OoxmlPackage::xml(str_replace('&', '&&', $company)) . '&amp;RPágina &amp;P de &amp;N';
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="' . self::MAIN_NS . '" xmlns:r="' . self::R_NS . '">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<dimension ref="A1:' . $lastCol . max($nextRow, $headerRow) . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"' . ($selected ? ' tabSelected="1"' : '') . ' showGridLines="0">'
            . '<pane ySplit="' . $headerRow . '" topLeftCell="A' . $firstData . '" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $cols
            . '<sheetData>' . $xml . '</sheetData>'
            . '<autoFilter ref="A' . $headerRow . ':' . $lastCol . $filterEnd . '"/>'
            . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="' . $landscape . '" fitToWidth="1" fitToHeight="0"/>'
            . '<headerFooter><oddFooter>' . $footer . '</oddFooter></headerFooter>'
            . ($logo !== null ? '<drawing r:id="rIdLogo"/>' : '')
            . '</worksheet>';

        return [$document, $headerRow, $filterEnd, $lastCol];
    }

    private function text(string $ref, string $text, int $style): string
    {
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . OoxmlPackage::xml($text) . '</t></is></c>';
    }

    private function value(string $ref, $value, array $column, int $style): string
    {
        if ($value === null) {
            return '<c r="' . $ref . '" s="' . $style . '"/>';
        }
        switch ($column['type']) {
            case 'text':
                return $this->text($ref, (string) $value, $style);
            case 'date':
                return '<c r="' . $ref . '" s="' . $style . '"><v>' . SpreadsheetSpec::excelDate((string) $value) . '</v></c>';
            case 'percent':
                return '<c r="' . $ref . '" s="' . $style . '"><v>' . OoxmlPackage::number(round((float) $value / 100, 10)) . '</v></c>';
            default:
                return '<c r="' . $ref . '" s="' . $style . '"><v>' . OoxmlPackage::number($value) . '</v></c>';
        }
    }

    /** Valor que mostraría el SUBTOTAL (cacheado para visores que no recalculan). */
    private static function aggregate(array $column, array $values): int|float|null
    {
        $present = array_values(array_filter($values, static fn($v): bool => $v !== null));
        if ($column['total'] === 'count') {
            return count($present);
        }
        if ($present === []) {
            return null;
        }
        $numbers = array_map(static fn($v): float => $column['type'] === 'percent' ? (float) $v / 100 : (float) $v, $present);
        $result = match ($column['total']) {
            'sum' => array_sum($numbers),
            'average' => array_sum($numbers) / count($numbers),
            'min' => min($numbers),
            'max' => max($numbers),
            default => null,
        };

        return $result === null ? null : round($result, 10);
    }

    /** Ancho en caracteres según la cabecera y el contenido (8-60). */
    private static function width(array $column, array $values): float
    {
        $max = mb_strlen($column['label']) + 2;
        foreach (array_slice($values, 0, 2000) as $value) {
            if ($value === null) {
                continue;
            }
            $length = match ($column['type']) {
                'date' => 10,
                'text' => mb_strlen((string) $value),
                'percent' => strlen(number_format((float) $value, $column['decimals'])) + 2,
                default => (int) ceil(strlen(number_format((float) $value, $column['decimals'] ?? 0)) * 1.1) + ($column['type'] === 'currency' ? 2 : 0),
            };
            $max = max($max, $length);
        }

        return (float) min(60, max(8, $max + 2));
    }

    // ------------------------------------------------------------------
    // Estilos
    // ------------------------------------------------------------------

    /** Índice de cellXfs para un uso (se crea la combinación la primera vez). */
    private function xf(string $role, $columnOrAlign = null, bool $zebra = false): int
    {
        $column = is_array($columnOrAlign) ? $columnOrAlign : null;
        $align = is_string($columnOrAlign) ? $columnOrAlign : null;
        [$font, $fill, $border] = match ($role) {
            'title' => [2, 0, 0],
            'subtitle' => [3, 0, 0],
            'header' => [1, 2, 0],
            'total', 'total-label' => [4, 0, 2],
            'note' => [5, 0, 0],
            default => [0, $zebra ? 3 : 0, 1],
        };
        $numFmt = $column !== null ? $this->numFmt($column) : 0;
        $key = implode('|', [$font, $fill, $border, $numFmt, $align ?? '']);
        if (false === isset($this->xfs[$key])) {
            // El 0 queda para el estilo por defecto de Excel.
            $this->xfs[$key] = count($this->xfs) + 1;
        }

        return $this->xfs[$key];
    }

    private function numFmt(array $column): int
    {
        $decimals = (int) ($column['decimals'] ?? 0);
        $fraction = $decimals > 0 ? '.' . str_repeat('0', $decimals) : '';
        $code = match ($column['type']) {
            'text' => null,
            'date' => 'dd/mm/yyyy',
            'percent' => '0' . $fraction . '%',
            'currency' => '#,##0' . $fraction . '\ "' . (self::CURRENCY_SYMBOLS[$column['currency'] ?? 'EUR'] ?? ($column['currency'] ?? 'EUR')) . '"',
            default => '#,##0' . $fraction,
        };
        if ($code === null) {
            return 49; // Texto (@)
        }
        if (false === isset($this->numFmts[$code])) {
            $this->numFmts[$code] = 164 + count($this->numFmts);
        }

        return $this->numFmts[$code];
    }

    private function styles(): string
    {
        $t = $this->theme;
        $font = OoxmlPackage::xml($t['font']['name']);
        $color = static fn(string $hex): string => '<color rgb="FF' . OoxmlPackage::hex($hex) . '"/>';

        $numFmts = '';
        foreach ($this->numFmts as $code => $id) {
            $numFmts .= '<numFmt numFmtId="' . $id . '" formatCode="' . OoxmlPackage::xml($code) . '"/>';
        }
        $fonts = [
            '<font><sz val="10"/>' . $color($t['text']) . '<name val="' . $font . '"/></font>',
            '<font><b/><sz val="10"/>' . $color($t['headerText']) . '<name val="' . $font . '"/></font>',
            '<font><b/><sz val="15"/>' . $color($t['primary']) . '<name val="' . $font . '"/></font>',
            '<font><i/><sz val="10"/>' . $color($t['secondary']) . '<name val="' . $font . '"/></font>',
            '<font><b/><sz val="10"/>' . $color($t['text']) . '<name val="' . $font . '"/></font>',
            '<font><i/><sz val="9"/>' . $color($t['secondary']) . '<name val="' . $font . '"/></font>',
        ];
        $fills = [
            '<fill><patternFill patternType="none"/></fill>',
            '<fill><patternFill patternType="gray125"/></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FF' . OoxmlPackage::hex($t['primary']) . '"/><bgColor indexed="64"/></patternFill></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FF' . OoxmlPackage::hex($t['zebra']) . '"/><bgColor indexed="64"/></patternFill></fill>',
        ];
        $light = OoxmlPackage::hex(OoxmlPackage::hex($t['zebra']) === 'FFFFFF' ? '#E5E7EB' : OfficeTheme::mix($t['zebra'], '#9CA3AF', 0.35));
        $borders = [
            '<border><left/><right/><top/><bottom/><diagonal/></border>',
            '<border><left/><right/><top/><bottom style="thin"><color rgb="FF' . $light . '"/></bottom><diagonal/></border>',
            '<border><left/><right/><top style="medium"><color rgb="FF' . OoxmlPackage::hex($t['primary']) . '"/></top><bottom/><diagonal/></border>',
        ];

        $xfs = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
        foreach ($this->xfs as $key => $index) {
            [$fontId, $fillId, $borderId, $numFmtId, $align] = explode('|', $key);
            $alignment = $align !== '' ? '<alignment horizontal="' . $align . '" vertical="center"/>' : ($fontId === '2' ? '<alignment vertical="center"/>' : '');
            $xfs .= '<xf numFmtId="' . $numFmtId . '" fontId="' . $fontId . '" fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"'
                . ($numFmtId !== '0' ? ' applyNumberFormat="1"' : '') . ' applyFont="1"' . ($fillId !== '0' ? ' applyFill="1"' : '') . ($borderId !== '0' ? ' applyBorder="1"' : '')
                . ($alignment !== '' ? ' applyAlignment="1">' . $alignment . '</xf>' : '/>');
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="' . self::MAIN_NS . '">'
            . ($numFmts !== '' ? '<numFmts count="' . count($this->numFmts) . '">' . $numFmts . '</numFmts>' : '')
            . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
            . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
            . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . (count($this->xfs) + 1) . '">' . $xfs . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    // ------------------------------------------------------------------
    // Partes fijas
    // ------------------------------------------------------------------

    private static function drawing(array $logo, int $columns): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="' . self::R_NS . '">'
            . '<xdr:oneCellAnchor><xdr:from><xdr:col>' . $columns . '</xdr:col><xdr:colOff>76200</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>19050</xdr:rowOff></xdr:from>'
            . '<xdr:ext cx="' . $logo['cx'] . '" cy="' . $logo['cy'] . '"/>'
            . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Logo"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
            . '<xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $logo['cx'] . '" cy="' . $logo['cy'] . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
            . '<xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>';
    }

    private static function contentTypes(int $sheets, ?array $logo): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . ($logo !== null ? '<Default Extension="' . $logo['ext'] . '" ContentType="' . $logo['mime'] . '"/>' : '')
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $sheets; $i++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        if ($logo !== null) {
            $xml .= '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        }

        return $xml . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }
}
