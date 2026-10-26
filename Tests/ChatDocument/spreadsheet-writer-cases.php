<?php

declare(strict_types=1);

/**
 * Casos de los escritores de hojas de cálculo: XLSX propio (OOXML con ZipArchive,
 * como el DOCX) y CSV para Excel en español. Los ejecuta ChatSpreadsheetTest.
 */

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\OfficeTheme;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\SpreadsheetCsvWriter;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\SpreadsheetSpec;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\SpreadsheetXlsxWriter;

require_once __DIR__ . '/asserts.php';

[$spec] = SpreadsheetSpec::validate([
    'title' => 'Ventas T3 2026',
    'subtitle' => 'Facturas de venta del ERP',
    'sheets' => [
        [
            'name' => 'Ventas',
            'columns' => [
                'Cliente',
                ['label' => 'Fecha', 'type' => 'date'],
                ['label' => 'Unidades', 'type' => 'integer', 'total' => 'sum'],
                ['label' => 'Importe', 'type' => 'currency', 'total' => 'sum'],
                ['label' => 'Margen', 'type' => 'percent', 'decimals' => 1, 'total' => 'average'],
            ],
            'rows' => [
                ['Ferretería Martínez & Hijos <SL>', '2026-07-03', 12, 1250.5, 23.5],
                ['Construcciones Pérez', '2026-08-15', 7, 1234.56, 18.25],
                ['=HYPERLINK("http://x")', null, null, 99.94, 0],
            ],
            'note' => 'Fuente: facturas de venta.',
        ],
        ['name' => 'Resumen', 'columns' => ['Concepto', ['label' => 'Valor', 'type' => 'number']], 'rows' => [['Clientes', 3]]],
    ],
]);
check(is_array($spec), 'Fixture is valid.');

$logo = sys_get_temp_dir() . '/beply-xlsx-logo-' . getmypid() . '.png';
// PNG 2x1 válido.
file_put_contents($logo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAAEElEQVR4nGP8z8DAwMDAAAAOBAH/3VZy1QAAAABJRU5ErkJggg=='));
$branding = [
    'primary' => '#1F3A5F', 'secondary' => '#5B6B7F', 'tertiary' => '#F1F1F1', 'text' => '#1F2933',
    'font' => ['name' => 'Raleway', 'category' => 'sans', 'css' => "'Raleway', Helvetica, Arial, sans-serif"],
    'fontSize' => 10.0, 'logo' => ['path' => $logo, 'mime' => 'image/png'], 'company' => ['name' => 'Beply Demo SL'],
];

// Tema común (visor y escritores): contraste de la cabecera y cebra suave.
$theme = OfficeTheme::fromBranding($branding);
same('#1F3A5F', $theme['primary'], 'Theme primary color.');
same('#FFFFFF', $theme['headerText'], 'Dark header → white text.');
same('#000000', OfficeTheme::fromBranding(['primary' => '#F5D76E'] + $branding)['headerText'], 'Light header → dark text.');
check(preg_match('/^#[0-9A-F]{6}$/', $theme['zebra']) === 1 && $theme['zebra'] !== '#FFFFFF', 'Soft zebra color.');
same('Raleway', $theme['font']['name'], 'Theme font.');
same(true, $theme['logo'], 'Theme says there is a logo (never its path).');
check(false === str_contains(json_encode($theme), $logo), 'The theme never exposes server paths.');
same('Beply Demo SL', $theme['company'], 'Theme company name.');

// ---------------- XLSX ----------------
$bytes = SpreadsheetXlsxWriter::render($spec, $branding, new DateTimeImmutable('2026-10-23T10:00:00Z'));
check(str_starts_with($bytes, "PK\x03\x04"), 'XLSX is a ZIP.');
$tmp = tempnam(sys_get_temp_dir(), 'xlsxt');
file_put_contents($tmp, $bytes);
$zip = new ZipArchive();
same(true, $zip->open($tmp), 'ZIP opens.');
same('[Content_Types].xml', $zip->getNameIndex(0), 'Content types go first (file type detection).');
foreach (['_rels/.rels', 'docProps/core.xml', 'docProps/app.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/drawings/drawing1.xml', 'xl/media/logo.png'] as $part) {
    check($zip->locateName($part) !== false, 'Part ' . $part . ' exists.');
}
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
        $xml = $zip->getFromIndex($i);
        libxml_use_internal_errors(true);
        check(simplexml_load_string($xml) !== false, 'Well-formed XML: ' . $name);
    }
}
$workbook = $zip->getFromName('xl/workbook.xml');
check(str_contains($workbook, '<sheet name="Ventas" sheetId="1" r:id="rId1"/>') && str_contains($workbook, '<sheet name="Resumen" sheetId="2" r:id="rId2"/>'), 'Both sheets are declared.');
check(str_contains($workbook, '_xlnm.Print_Titles'), 'Header row repeats when printing.');
$styles = $zip->getFromName('xl/styles.xml');
check(str_contains($styles, 'rgb="FF1F3A5F"'), 'Header fill uses the primary color of the style.');
check(str_contains($styles, '<name val="Raleway"/>'), 'Font of the style.');
check(str_contains($styles, '#,##0.00\ &quot;€&quot;') || str_contains($styles, '#,##0.00 &quot;€&quot;'), 'Currency number format in euros.');
check(str_contains($styles, 'dd/mm/yyyy'), 'Spanish date format.');
check(str_contains($styles, '0.0%'), 'Percent format with the requested decimals.');

$sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
// Fila 1 título, 2 subtítulo, 4 cabecera, 5-7 datos, 8 totales.
check(str_contains($sheet, '<c r="A1"') && str_contains($sheet, 'Ventas T3 2026'), 'Title in A1.');
check(str_contains($sheet, 'Facturas de venta del ERP'), 'Subtitle row.');
check(str_contains($sheet, '<pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/>'), 'Header row is frozen.');
check(str_contains($sheet, '<autoFilter ref="A4:E7"/>'), 'Autofilter over header and data.');
check(str_contains($sheet, 'Ferretería Martínez &amp; Hijos &lt;SL&gt;'), 'Text is XML-escaped.');
check(str_contains($sheet, '<c r="B5" s="') && str_contains($sheet, '<v>46206</v>'), 'Dates are Excel serial numbers (2026-07-03 = 46206).');
check(str_contains($sheet, '<v>1250.5</v>'), 'Numbers are numbers.');
check(str_contains($sheet, '<v>0.235</v>'), 'Percent points are stored as fractions.');
check(str_contains($sheet, '<f>SUBTOTAL(109,D5:D7)</f><v>2585</v>'), 'Totals use SUBTOTAL (respects filters) with the cached value.');
check(str_contains($sheet, '<f>SUBTOTAL(101,E5:E7)</f>'), 'Average total.');
check(str_contains($sheet, '<f>SUBTOTAL(109,C5:C7)</f><v>19</v>'), 'Integer total.');
check(str_contains($sheet, 'Total'), 'Totals label.');
check(str_contains($sheet, 'Fuente: facturas de venta.'), 'Note under the table.');
check(false === str_contains($sheet, '<f>HYPERLINK'), 'Text that looks like a formula is never a formula.');
check(preg_match('/<col min="1" max="1" width="([0-9.]+)" customWidth="1"\/>/', $sheet, $m) === 1 && (float) $m[1] > 20, 'Columns are sized to their content.');
check(str_contains($sheet, '<drawing r:id="rIdLogo"/>'), 'Logo drawing on the first sheet.');
check(str_contains($zip->getFromName('xl/drawings/drawing1.xml'), '<xdr:col>5</xdr:col>'), 'Logo goes right of the last column.');
$sheet2 = $zip->getFromName('xl/worksheets/sheet2.xml');
check(false === str_contains($sheet2, 'SUBTOTAL'), 'No totals when no column asks for them.');
same('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', SpreadsheetXlsxWriter::MIME, 'XLSX MIME.');
$zip->close();
@unlink($tmp);

// Sin logo: sin dibujo.
$noLogo = SpreadsheetXlsxWriter::render($spec, ['logo' => null] + $branding);
$tmp = tempnam(sys_get_temp_dir(), 'xlsxt');
file_put_contents($tmp, $noLogo);
$zip = new ZipArchive();
$zip->open($tmp);
same(false, $zip->locateName('xl/drawings/drawing1.xml'), 'No logo → no drawing.');
$zip->close();
@unlink($tmp);
@unlink($logo);

// ---------------- CSV ----------------
$csv = SpreadsheetCsvWriter::render($spec, 0);
check(str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV starts with the UTF-8 BOM (Excel opens accents right).');
$lines = explode("\r\n", substr($csv, 3));
same('Cliente;Fecha;Unidades;Importe;Margen', $lines[0], 'Header with semicolons (Excel in Spanish).');
same('Ferretería Martínez & Hijos <SL>;03/07/2026;12;1250,50;23,5%', $lines[1], 'Decimal comma, Spanish dates and percent.');
same('"\'=HYPERLINK(""http://x"")";;;99,94;0,0%', $lines[3], 'Formula-like text is neutralized and quoted.');
same('', end($lines), 'CRLF line endings, ending in a newline.');
same(5, count($lines), 'Header + 3 rows, no totals (data only).');
same("\xEF\xBB\xBFConcepto;Valor\r\nClientes;3,00\r\n", SpreadsheetCsvWriter::render($spec, 1), 'Second sheet.');
same("\xEF\xBB\xBFConcepto;Valor\r\nClientes;3,00\r\n", SpreadsheetCsvWriter::render($spec, 9), 'Out of range → last sheet.');
same('text/csv; charset=utf-8', SpreadsheetCsvWriter::MIME, 'CSV MIME.');
