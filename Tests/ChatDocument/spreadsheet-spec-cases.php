<?php

declare(strict_types=1);

/**
 * Casos de la especificación `beply.spreadsheet/v1` (hojas de cálculo del chat).
 * Los ejecuta ChatSpreadsheetTest.
 */

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\SpreadsheetSpec;

require_once __DIR__ . '/asserts.php';

same('beply.spreadsheet/v1', SpreadsheetSpec::CONTRACT, 'Contract name.');

$sales = [
    'schema' => 'beply.spreadsheet/v1',
    'title' => "Ventas del tercer\ntrimestre 2026",
    'subtitle' => 'Facturas de venta del ERP',
    'sheets' => [[
        'name' => 'Ventas: T3/2026',
        'columns' => [
            'Cliente',
            ['label' => 'Fecha', 'type' => 'date'],
            ['label' => 'Unidades', 'type' => 'integer', 'total' => 'sum'],
            ['label' => 'Importe', 'type' => 'currency', 'total' => 'sum'],
            ['label' => 'Margen', 'type' => 'percent', 'decimals' => 1, 'total' => 'average'],
        ],
        'rows' => [
            ['Ferretería Martínez', '2026-07-03', 12, 1250.5, 23.5],
            ['Construcciones Pérez', '15/08/2026', '7', '1.234,56 €', '18,25 %'],
            ['=HYPERLINK("x")', null, '', 99.999, 0],
            ['Corto'],
        ],
        'note' => 'Fuente: facturas de venta.',
        'style' => 'color:red',
    ]],
    'theme' => ['primary' => 'red'],
];
[$spec, $errors] = SpreadsheetSpec::validate($sales);
same([], $errors, 'A valid spreadsheet has no errors.');
same('Ventas del tercer trimestre 2026', $spec['title'], 'The title is a single line.');
same('Facturas de venta del ERP', $spec['subtitle'], 'Subtitle kept.');
same('Ventas T3 2026', $spec['sheets'][0]['name'], 'Excel-forbidden characters are removed from the sheet name.');
same(['label' => 'Cliente', 'type' => 'text', 'total' => 'none'], $spec['sheets'][0]['columns'][0], 'A string column is a text column.');
same(['label' => 'Importe', 'type' => 'currency', 'total' => 'sum', 'decimals' => 2, 'currency' => 'EUR'], $spec['sheets'][0]['columns'][3], 'Currency defaults to EUR with 2 decimals.');
same(['label' => 'Unidades', 'type' => 'integer', 'total' => 'sum', 'decimals' => 0], $spec['sheets'][0]['columns'][2], 'Integers have no decimals.');
same(['Ferretería Martínez', '2026-07-03', 12, 1250.5, 23.5], $spec['sheets'][0]['rows'][0], 'Typed values stay typed.');
same(['Construcciones Pérez', '2026-08-15', 7, 1234.56, 18.25], $spec['sheets'][0]['rows'][1], 'Spanish numbers and dates are normalized.');
same(['=HYPERLINK("x")', null, null, 99.999, 0], $spec['sheets'][0]['rows'][2], 'Empty cells are null and text is kept as text.');
same(['Corto', null, null, null, null], $spec['sheets'][0]['rows'][3], 'Short rows are padded.');
same('Fuente: facturas de venta.', $spec['sheets'][0]['note'], 'Note kept.');
check(isset($spec['_warnings']) && count($spec['_warnings']) >= 2, 'Style properties are ignored with a warning.');
check(false === isset(SpreadsheetSpec::withoutWarnings($spec)['_warnings']), 'Warnings are not stored.');

// Errores por ruta: el modelo corrige exactamente la celda.
[$bad, $errors] = SpreadsheetSpec::validate([
    'title' => '',
    'sheets' => [[
        'columns' => [['label' => 'Fecha', 'type' => 'date'], ['label' => 'Importe', 'type' => 'money'], ['label' => 'Cliente', 'total' => 'sum']],
        'rows' => [['31/02/2026', 1, 'x'], ['2026-01-01', 'mucho', 'y', 'extra']],
    ]],
]);
same(null, $bad, 'Invalid spreadsheets are rejected.');
$paths = paths($errors);
foreach (['title', 'sheets[0].columns[1].type', 'sheets[0].columns[2].total', 'sheets[0].rows[0][0]', 'sheets[0].rows[1]'] as $path) {
    check(in_array($path, $paths, true), 'Error path ' . $path . ' in ' . json_encode($paths));
}

[$bad, $errors] = SpreadsheetSpec::validate(['title' => 'Números', 'sheets' => [['columns' => [['label' => 'N', 'type' => 'number']], 'rows' => [['doce']]]]]);
same('sheets[0].rows[0][0]', $errors[0]['path'] ?? null, 'Text in a number column is an error at that cell.');
same('not-a-number', $errors[0]['code'] ?? null, 'Error code for non numbers.');

[$bad, $errors] = SpreadsheetSpec::validate(['title' => 'Sin hojas', 'sheets' => []]);
same(['sheets'], paths($errors), 'At least one sheet.');

[$bad, $errors] = SpreadsheetSpec::validate(['schema' => 'beply.document/v1', 'title' => 'x', 'sheets' => [['columns' => ['A'], 'rows' => []]]]);
same(['schema'], paths($errors), 'Another schema is rejected.');

// Límites: columnas, filas y celdas.
$tooWide = ['title' => 'Ancha', 'sheets' => [['columns' => array_fill(0, SpreadsheetSpec::MAX_COLUMNS + 1, 'C'), 'rows' => []]]];
same('sheets[0].columns', SpreadsheetSpec::validate($tooWide)[1][0]['path'] ?? null, 'Column limit.');
$tooLong = ['title' => 'Larga', 'sheets' => [['columns' => ['A'], 'rows' => array_fill(0, SpreadsheetSpec::MAX_ROWS + 1, ['x'])]]];
same('sheets[0].rows', SpreadsheetSpec::validate($tooLong)[1][0]['path'] ?? null, 'Row limit.');
$manySheets = ['title' => 'Muchas', 'sheets' => array_fill(0, SpreadsheetSpec::MAX_SHEETS + 1, ['columns' => ['A'], 'rows' => []])];
same('sheets', SpreadsheetSpec::validate($manySheets)[1][0]['path'] ?? null, 'Sheet limit.');

// Nombres de hoja: por defecto, únicos y como mucho 31 caracteres.
[$spec] = SpreadsheetSpec::validate(['title' => 'Varias', 'sheets' => [
    ['columns' => ['A'], 'rows' => [['1']]],
    ['name' => 'Resumen', 'columns' => ['A'], 'rows' => []],
    ['name' => 'resumen', 'columns' => ['A'], 'rows' => []],
    ['name' => str_repeat('Hoja larguísima ', 5), 'columns' => ['A'], 'rows' => []],
]]);
same(['Hoja 1', 'Resumen', 'resumen (2)', 'Hoja larguísima Hoja larguísima'], array_column($spec['sheets'], 'name'), 'Sheet names are defaulted, unique and ≤ 31 characters.');
check(mb_strlen($spec['sheets'][3]['name']) <= 31, 'Excel limit of 31 characters.');

// Texto plano para límites y búsquedas.
check(str_contains(SpreadsheetSpec::plainText($spec), 'Varias'), 'Plain text includes the title.');
