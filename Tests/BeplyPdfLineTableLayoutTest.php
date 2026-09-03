<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 *
 * Contrato del reparto de columnas de la tabla de líneas: ninguna columna nace sin ancho, las
 * externas entran en el 100% y, cuando el contenido no cabe, el motor cambia de densidad en
 * vez de dejar que las celdas se salgan del recuadro.
 */

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineTableLayout;
use PHPUnit\Framework\TestCase;

final class BeplyPdfLineTableLayoutTest extends TestCase
{
    private const A4_USABLE_PT = 510.2; // 595,3 - 2 x 15 mm

    public function testConfiguredProportionsAreKeptWhenEveryColumnAlreadyFits(): void
    {
        // Anchos configurados en los que cada celda ya tiene sitio para su texto MÁS el padding real
        // de la plantilla: el motor no toca nada y sólo normaliza a 100.
        $columns = [
            $this->column('descripcion', 40.0, 30.0, 7.5, true),
            $this->column('cantidad', 10.0, 2.6, 3.2),
            $this->column('pvpunitario', 13.0, 5.0, 4.1),
            $this->column('dtopor', 11.0, 3.5, 3.0),
            $this->column('pvptotal', 14.0, 5.4, 2.9),
            $this->column('iva', 12.0, 3.9, 2.1),
        ];
        $layout = BeplyPdfLineTableLayout::resolve($columns, self::A4_USABLE_PT, 10);

        $this->assertSame(BeplyPdfLineTableLayout::MODE_NORMAL, $layout['mode']);
        $this->assertSame(10, $layout['font_px']);
        $this->assertFalse($layout['wrap']);
        $this->assertSame([40.0, 10.0, 13.0, 11.0, 14.0, 12.0], $layout['widths']);
    }

    public function testDefaultSixColumnsGiveEveryFixedColumnItsTextPlusThePadding(): void
    {
        // Con los anchos por defecto (48/8/13/7/14/7) la columna IVA recibe 7,22 % = 36,8 pt, pero
        // «21,00%» a 10 px mide 29 pt y la celda lleva 12 px de padding a cada lado: 47 pt. En una
        // celda nowrap ese texto invade el padding y, en la última columna, sale del recuadro.
        $layout = BeplyPdfLineTableLayout::resolve($this->defaultColumns(), self::A4_USABLE_PT, 10);

        $this->assertSame(BeplyPdfLineTableLayout::MODE_NORMAL, $layout['mode']);
        $this->assertNoFixedColumnBelowItsNeed($this->defaultColumns(), $layout, self::A4_USABLE_PT);
        $this->assertGreaterThan($layout['widths'][1], $layout['widths'][0], 'description keeps the largest share');
        $this->assertEqualsWithDelta(100.0, array_sum($layout['widths']), 0.05);
    }

    public function testOsmosisSixColumnsWithoutWidthsAtTwelvePixelsKeepTheVatCellInsideTheFrame(): void
    {
        // Caso real (03-09-2026): diseño Enmarcado, letra 12 px, seis columnas añadidas desde el editor con
        // ancho 0 (el motor usa pesos automáticos) y una sola línea «1,00 · 7,43 € · 7,43 € · 21,00%».
        // Medido con WeasyPrint+poppler: «21,00%» terminaba en 557,0 pt con el margen útil en 552,8 pt.
        $columns = [
            $this->column('descripcion', 48.0, 12.9, 8.6, true),
            $this->column('cantidad', 8.0, 2.24, 3.4),
            $this->column('pvpunitario', 13.0, 3.2, 4.4),
            $this->column('pvptotal', 14.0, 3.2, 3.1),
            $this->column('iva', 7.0, 3.83, 2.3),
        ];
        $layout = BeplyPdfLineTableLayout::resolve($columns, self::A4_USABLE_PT, 12);

        $this->assertSame(BeplyPdfLineTableLayout::MODE_NORMAL, $layout['mode']);
        $vatPt = self::A4_USABLE_PT * $layout['widths'][4] / 100.0;
        $this->assertTrue($vatPt + 0.05 >= 3.83 * 9.0 + 2 * 12 * 0.75, sprintf('IVA needs %.1fpt (text + padding) but got %.1fpt', 3.83 * 9.0 + 18.0, $vatPt));
        $this->assertNoFixedColumnBelowItsNeed($columns, $layout, self::A4_USABLE_PT);
    }

    public function testWidthsAlwaysAddUpToOneHundred(): void
    {
        foreach ([$this->defaultColumns(), $this->twelveColumns(), $this->twelveColumns(true)] as $columns) {
            foreach ([self::A4_USABLE_PT, 335.0, 200.0] as $usable) {
                $layout = BeplyPdfLineTableLayout::resolve($columns, $usable, 10);
                $this->assertEqualsWithDelta(100.0, array_sum($layout['widths']), 0.05);
                $this->assertCount(count($columns), $layout['widths']);
            }
        }
    }

    public function testAColumnAddedWithoutWidthStillGetsRoomForItsContent(): void
    {
        $columns = $this->defaultColumns();
        $columns[] = $this->column('recargo', 0.0, 3.9, 2.0);
        $layout = BeplyPdfLineTableLayout::resolve($columns, self::A4_USABLE_PT, 10);

        $widthPt = self::A4_USABLE_PT * $layout['widths'][6] / 100.0;
        $this->assertGreaterThan(0.0, $layout['widths'][6]);
        $this->assertGreaterThan(3.9 * 7.5 + 2 * $layout['pad_x_px'] * 0.75 - 0.01, $widthPt);
        $this->assertGreaterThan($layout['widths'][1], $layout['widths'][0], 'description keeps the largest share');
    }

    public function testExternalColumnJoinsTheSharedHundredPercent(): void
    {
        $columns = $this->defaultColumns();
        $columns[] = $this->column('lote', 0.0, 4.2, 2.7, false, true);
        $layout = BeplyPdfLineTableLayout::resolve($columns, self::A4_USABLE_PT, 10);

        $this->assertGreaterThan(5.0, $layout['widths'][6]);
        $this->assertEqualsWithDelta(100.0, array_sum($layout['widths']), 0.05);
    }

    public function testTwelveColumnsSwitchDensityInsteadOfOverflowing(): void
    {
        $layout = BeplyPdfLineTableLayout::resolve($this->twelveColumns(), self::A4_USABLE_PT, 10);

        $this->assertTrue(in_array($layout['mode'], [BeplyPdfLineTableLayout::MODE_COMPACT, BeplyPdfLineTableLayout::MODE_DENSE], true), $layout['mode']);
        $this->assertFalse($layout['wrap']);
        $this->assertNoFixedColumnBelowItsNeed($this->twelveColumns(), $layout, self::A4_USABLE_PT);
    }

    public function testTwelveColumnsWithUserWidthsAtZeroBehaveLikeTheEditorLeavesThem(): void
    {
        $layout = BeplyPdfLineTableLayout::resolve($this->twelveColumns(true), self::A4_USABLE_PT, 10);

        foreach ($layout['widths'] as $width) {
            $this->assertGreaterThan(2.0, $width);
        }
        $this->assertNoFixedColumnBelowItsNeed($this->twelveColumns(true), $layout, self::A4_USABLE_PT);
    }

    public function testImpossibleFitFallsBackToWrappingCells(): void
    {
        $layout = BeplyPdfLineTableLayout::resolve($this->twelveColumns(), 200.0, 10);

        $this->assertSame(BeplyPdfLineTableLayout::MODE_WRAP, $layout['mode']);
        $this->assertTrue($layout['wrap']);
        $this->assertEqualsWithDelta(100.0, array_sum($layout['widths']), 0.05);
    }

    public function testEmWidthFollowsGlyphClasses(): void
    {
        $digits = BeplyPdfLineTableLayout::emWidth('21,00%');
        $this->assertTrue($digits >= 3.4 && $digits <= 4.4, (string) $digits);
        $this->assertGreaterThan(BeplyPdfLineTableLayout::emWidth('abc'), BeplyPdfLineTableLayout::emWidth('ABC'));
        $this->assertSame(0.0, BeplyPdfLineTableLayout::emWidth('   '));
        $this->assertSame(BeplyPdfLineTableLayout::emWidth('VENCIMIENTO'), BeplyPdfLineTableLayout::longestWordEm('Fecha de VENCIMIENTO'));
    }

    public function testATwoWordHeaderClaimsItsWholeWidthWhileHeadersCannotBreak(): void
    {
        // «PRECIO CON IVA» (columna pvpunitarioiva) a 12 px: en densidad normal la cabecera nativa va en una sola
        // línea (nowrap), así que necesita las tres palabras, no sólo «PRECIO». Medido el 03-09-2026: con la
        // palabra más larga como necesidad, «CON IVA» pisaba la columna NETO.
        $columns = [
            $this->column('descripcion', 48.0, 12.9, 8.6, true),
            $this->column('cantidad', 8.0, 2.24, 3.4),
            ['key' => 'pvpunitarioiva', 'weight' => 13.0, 'content_em' => 3.2, 'label_em' => 4.67, 'label_full_em' => 10.0, 'flexible' => false, 'external' => false],
            $this->column('pvptotal', 14.0, 3.2, 3.1),
            $this->column('iva', 7.0, 3.83, 2.3),
        ];
        $layout = BeplyPdfLineTableLayout::resolve($columns, self::A4_USABLE_PT, 12);

        $this->assertSame(BeplyPdfLineTableLayout::MODE_NORMAL, $layout['mode']);
        $pricePt = self::A4_USABLE_PT * $layout['widths'][2] / 100.0;
        $this->assertTrue($pricePt + 0.05 >= 10.0 * 9.0 + 18.0, sprintf('two-word header needs %.1fpt but got %.1fpt', 108.0, $pricePt));

        // En compact/dense la cabecera parte por palabras y basta con la palabra más larga.
        $narrow = BeplyPdfLineTableLayout::resolve($columns, 300.0, 12);
        $this->assertTrue($narrow['mode'] !== BeplyPdfLineTableLayout::MODE_NORMAL, $narrow['mode']);
        $narrowPricePt = 300.0 * $narrow['widths'][2] / 100.0;
        $this->assertTrue($narrowPricePt < 10.0 * $narrow['font_px'] * 0.75 + 2 * $narrow['pad_x_px'] * 0.75, 'in compact the header may break by words');
    }

    public function testDescriptionSqueezedBelowAQuarterEscalatesDensityInsteadOfShrinking(): void
    {
        // Ocho columnas a 12 px caben en densidad normal, pero con el padding real de cada celda la descripción
        // se quedaría en ~19 % (medido en Estudio con la externa E2E el 03-09-2026). El contrato de render exige
        // que la descripción siga siendo dominante (≥ 25 %): el motor baja a compact antes que estrujarla.
        $columns = [
            $this->column('referencia', 12.0, 4.4, 6.6),
            $this->column('descripcion', 48.0, 6.1, 7.3, true),
            $this->column('cantidad', 8.0, 2.24, 3.5),
            $this->column('pvpunitario', 13.0, 3.84, 4.7),
            $this->column('dtopor', 7.0, 3.83, 4.1),
            $this->column('iva', 7.0, 3.83, 2.3),
            $this->column('pvptotal', 14.0, 3.84, 3.1),
            $this->column('lote', 10.6, 11.1, 5.3, false, true),
        ];
        $layout = BeplyPdfLineTableLayout::resolve($columns, 527.0, 12);

        $this->assertSame(BeplyPdfLineTableLayout::MODE_COMPACT, $layout['mode']);
        $this->assertTrue($layout['widths'][1] >= 25.0, sprintf('description got %.2f%%', $layout['widths'][1]));
        $this->assertNoFixedColumnBelowItsNeed($columns, $layout, 527.0);

        // Una descripción configurada deliberadamente estrecha (20 %) que ya cabe NO fuerza el cambio de densidad.
        $narrowByChoice = [
            $this->column('descripcion', 20.0, 6.1, 7.3, true),
            $this->column('cantidad', 20.0, 2.24, 3.5),
            $this->column('pvpunitario', 30.0, 3.84, 4.7),
            $this->column('pvptotal', 30.0, 3.84, 3.1),
        ];
        $kept = BeplyPdfLineTableLayout::resolve($narrowByChoice, self::A4_USABLE_PT, 10);
        $this->assertSame(BeplyPdfLineTableLayout::MODE_NORMAL, $kept['mode']);
        $this->assertSame([20.0, 20.0, 30.0, 30.0], $kept['widths']);
    }

    /** @param array<int, array> $columns */
    private function assertNoFixedColumnBelowItsNeed(array $columns, array $layout, float $usablePt): void
    {
        $fontPt = $layout['font_px'] * 0.75;
        $padPt = 2 * $layout['pad_x_px'] * 0.75;
        foreach ($columns as $i => $column) {
            if ($column['flexible']) {
                continue;
            }
            // Una columna externa parte líneas por encima de 6 em: no reclama más que eso (EXTERNAL_MAX_EM).
            $em = !empty($column['external'])
                ? max(min($column['content_em'], 6.0), min($column['label_em'], 6.0))
                : max($column['content_em'], $column['label_em']);
            $need = $em * $fontPt + $padPt;
            $got = $usablePt * $layout['widths'][$i] / 100.0;
            $this->assertTrue($got + 0.05 >= $need, sprintf('%s needs %.1fpt but got %.1fpt', $column['key'], $need, $got));
        }
    }

    private function column(string $key, float $weight, float $contentEm, float $labelEm, bool $flexible = false, bool $external = false): array
    {
        return ['key' => $key, 'weight' => $weight, 'content_em' => $contentEm, 'label_em' => $labelEm, 'flexible' => $flexible, 'external' => $external];
    }

    /** @return array<int, array> */
    private function defaultColumns(): array
    {
        return [
            $this->column('descripcion', 48.0, 30.0, 7.5, true),
            $this->column('cantidad', 8.0, 2.6, 3.2),
            $this->column('pvpunitario', 13.0, 5.0, 4.1),
            $this->column('dtopor', 7.0, 3.5, 3.0),
            $this->column('pvptotal', 14.0, 5.4, 2.9),
            $this->column('iva', 7.0, 3.9, 2.1),
        ];
    }

    /** @return array<int, array> */
    private function twelveColumns(bool $editorWidths = false): array
    {
        return [
            $this->column('numlinea', $editorWidths ? 0.0 : 5.0, 1.4, 0.7),
            $this->column('referencia', $editorWidths ? 0.0 : 12.0, 5.4, 7.2),
            $this->column('descripcion', 48.0, 40.0, 7.5, true),
            $this->column('cantidad', 8.0, 2.6, 3.2),
            $this->column('pvpunitario', 13.0, 5.0, 4.1),
            $this->column('dtopor', 7.0, 3.5, 3.0),
            $this->column('pvptotal', 14.0, 5.4, 2.9),
            $this->column('iva', 7.0, 3.9, 2.1),
            $this->column('recargo', $editorWidths ? 0.0 : 7.0, 3.5, 1.5),
            $this->column('irpf', $editorWidths ? 0.0 : 7.0, 4.2, 2.8),
            $this->column('totaliva', $editorWidths ? 0.0 : 14.0, 6.0, 3.6),
            $this->column('lote', 0.0, 4.2, 2.7, false, true),
        ];
    }
}
