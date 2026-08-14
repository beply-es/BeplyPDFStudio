<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfGenericReportBuffer;
use PHPUnit\Framework\TestCase;

final class BeplyPdfGenericReportBufferTest extends TestCase
{
    public function testKeepsParametersBeforeAccountingRows(): void
    {
        $buffer = new BeplyPdfGenericReportBuffer();
        $buffer->start(
            ['title' => 'Balance', 'orientation' => 'portrait'],
            ['kind' => 'model', 'columns' => [['label' => 'Campo']], 'rows' => [['PARAMETROS']]]
        );
        $this->assertTrue($buffer->appendTable([
            'kind' => 'table',
            'columns' => [['label' => 'Cuenta'], ['label' => 'Saldo']],
            'rows' => [['430', '3388']],
        ]));

        $payload = $buffer->peek();
        $this->assertSame('model', $payload['sections'][0]['kind']);
        $this->assertSame('table', $payload['sections'][1]['kind']);
        $this->assertSame('PARAMETROS', $payload['sections'][0]['rows'][0][0]);
        $this->assertSame('430', $payload['sections'][1]['rows'][0][0]);
    }

    public function testMergesConsecutivePagesWithTheSameColumns(): void
    {
        $buffer = new BeplyPdfGenericReportBuffer();
        $buffer->start([], ['kind' => 'model', 'columns' => [['label' => 'Campo']], 'rows' => []]);
        $section = [
            'kind' => 'table',
            'columns' => [['label' => 'Cuenta'], ['label' => 'Saldo']],
            'rows' => [['430', '10']],
            'native_rows' => [['account' => '430', 'balance' => '10']],
        ];
        $buffer->appendTable($section);
        $section['rows'] = [['400', '20']];
        $section['native_rows'] = [['account' => '400', 'balance' => '20']];
        $buffer->appendTable($section);

        $payload = $buffer->peek();
        $this->assertCount(2, $payload['sections']);
        $this->assertCount(2, $payload['sections'][1]['rows']);
        $this->assertCount(2, $payload['sections'][1]['native_rows']);
        $this->assertSame('400', $payload['sections'][1]['native_rows'][1]['account']);
    }

    public function testWideReportSwitchesToLandscapeAndPullClearsTheBuffer(): void
    {
        $buffer = new BeplyPdfGenericReportBuffer();
        $buffer->start(['orientation' => 'portrait'], ['kind' => 'model', 'columns' => [['label' => 'Campo']], 'rows' => []]);
        $buffer->appendTable([
            'kind' => 'table',
            'columns' => array_fill(0, 6, ['label' => 'Columna']),
            'rows' => [],
        ]);

        $this->assertSame('landscape', $buffer->pull()['orientation']);
        $this->assertFalse($buffer->hasPending());
        $this->assertNull($buffer->pull());
    }
}
