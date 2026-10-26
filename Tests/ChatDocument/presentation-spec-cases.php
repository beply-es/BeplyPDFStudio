<?php

declare(strict_types=1);

/**
 * Casos de la especificación `beply.presentation/v1` (presentaciones del chat).
 * Los ejecuta ChatPresentationTest.
 */

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\PresentationSpec;

require_once __DIR__ . '/asserts.php';

same('beply.presentation/v1', PresentationSpec::CONTRACT, 'Contract name.');
same(['title', 'section', 'bullets', 'table', 'chart', 'kpis'], PresentationSpec::LAYOUTS, 'Layouts.');

$deck = [
    'schema' => 'beply.presentation/v1',
    'title' => "Ventas del\ntercer trimestre",
    'subtitle' => 'Comité de dirección',
    'date' => '2026-10-23',
    'theme' => ['color' => 'red'],
    'slides' => [
        ['layout' => 'title', 'title' => 'Ventas T3 2026', 'subtitle' => 'Datos del ERP'],
        ['layout' => 'kpis', 'title' => 'Resumen', 'items' => [
            ['label' => 'Facturación', 'value' => '37.741,50 €', 'detail' => '+12 % frente a T2'],
            ['label' => 'Clientes', 'value' => 5],
        ]],
        ['layout' => 'chart', 'title' => 'Ventas por mes', 'chart' => [
            'type' => 'column', 'categories' => ['Julio', 'Agosto', 'Septiembre'], 'unit' => '€',
            'series' => [['name' => '2026', 'values' => [12000, '9.500,50', 16241]], ['name' => '2025', 'values' => [10000, 8000, 15000]]],
        ], 'caption' => 'Base imponible'],
        ['layout' => 'chart', 'title' => 'Reparto', 'chart' => ['type' => 'pie', 'categories' => ['A', 'B'], 'series' => [['name' => 'Peso', 'values' => [60, 40]]]]],
        ['layout' => 'table', 'title' => 'Top clientes', 'header' => ['Cliente', 'Importe'], 'rows' => [['Aldea', '8.387,00 €'], ['Norte', 7049.2]]],
        ['layout' => 'bullets', 'title' => 'Conclusiones', 'bullets' => ['Septiembre fue el **mejor** mes', '', 'Hay que vigilar los cobros'], 'note' => 'Fuente: ERP'],
        ['layout' => 'section', 'title' => 'Próximos pasos', 'style' => 'big'],
    ],
];
[$spec, $errors] = PresentationSpec::validate($deck);
same([], $errors, 'A valid deck has no errors.');
same('Ventas del tercer trimestre', $spec['title'], 'Title is one line.');
same('2026-10-23', $spec['date'], 'Date kept.');
same(7, count($spec['slides']), 'All slides kept.');
same(['label' => 'Clientes', 'value' => '5', 'detail' => ''], $spec['slides'][1]['items'][1], 'KPI values are text.');
same([12000, 9500.5, 16241], $spec['slides'][2]['chart']['series'][0]['values'], 'Chart values are numbers (Spanish format accepted).');
same('€', $spec['slides'][2]['chart']['unit'], 'Chart unit.');
same('Base imponible', $spec['slides'][2]['caption'], 'Caption.');
same([['Aldea', '8.387,00 €'], ['Norte', '7049.2']], $spec['slides'][4]['rows'], 'Table cells are text.');
same(['Septiembre fue el **mejor** mes', 'Hay que vigilar los cobros'], $spec['slides'][5]['bullets'], 'Empty bullets are dropped; inline bold is kept for the renderers.');
check(isset($spec['_warnings']) && count($spec['_warnings']) >= 2, 'Style properties are ignored with a warning.');
check(str_contains(PresentationSpec::plainText($spec), 'Conclusiones'), 'Plain text includes slide titles.');

[$bad, $errors] = PresentationSpec::validate([
    'title' => 'Mal',
    'slides' => [
        ['layout' => 'hero', 'title' => 'x'],
        ['layout' => 'bullets', 'title' => '', 'bullets' => []],
        ['layout' => 'chart', 'title' => 'C', 'chart' => ['type' => 'pie', 'categories' => ['A', 'B'], 'series' => [['name' => 'x', 'values' => [1, 2]], ['name' => 'y', 'values' => [1, 2]]]]],
        ['layout' => 'chart', 'title' => 'D', 'chart' => ['type' => 'line', 'categories' => ['A', 'B'], 'series' => [['name' => 'x', 'values' => [1, 'mucho', 3]]]]],
        ['layout' => 'table', 'title' => 'T', 'header' => ['A'], 'rows' => [['1', '2']]],
        ['layout' => 'kpis', 'title' => 'K', 'items' => array_fill(0, 5, ['label' => 'x', 'value' => '1'])],
    ],
]);
same(null, $bad, 'Invalid decks are rejected.');
$paths = paths($errors);
foreach (['slides[0].layout', 'slides[1].title', 'slides[1].bullets', 'slides[2].chart.series', 'slides[3].chart.series[0].values', 'slides[3].chart.series[0].values[1]', 'slides[4].rows[0]', 'slides[5].items'] as $path) {
    check(in_array($path, $paths, true), 'Error path ' . $path . ' in ' . json_encode($paths));
}
same(['slides'], paths(PresentationSpec::validate(['title' => 'x', 'slides' => []])[1]), 'At least one slide.');
same('slides', PresentationSpec::validate(['title' => 'x', 'slides' => array_fill(0, PresentationSpec::MAX_SLIDES + 1, ['layout' => 'section', 'title' => 'S'])])[1][0]['path'] ?? null, 'Slide limit.');
same(['schema'], paths(PresentationSpec::validate(['schema' => 'beply.spreadsheet/v1', 'title' => 'x', 'slides' => [['layout' => 'section', 'title' => 'S']]])[1]), 'Another schema is rejected.');
