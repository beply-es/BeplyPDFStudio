<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine;

/**
 * Documento de MUESTRA para la vista previa: imita la superficie mínima de un
 * BusinessDocument que leen los renderers del motor, para poder
 * generar el PDF de preview con el MISMO motor real (WYSIWYG), sin necesitar un
 * documento guardado en la base de datos.
 */
class BeplyPdfSampleDoc
{
    private const PREVIEW_INVOICE_NOTICE = 'ESTA FACTURA ES 100% DE PRUEBA Y NO ES REAL';
    private const PREVIEW_DOCUMENT_NOTICE = 'ESTE DOCUMENTO ES 100% DE PRUEBA Y NO ES REAL';

    public $idempresa;
    public $codigo = '2026/0001';
    public $numero = 'NUM-2026-XYZ';
    public $codigorect = '2025/0099';
    public $codserie = 'A';
    public $numero2 = 'EXT-2026-42';
    public $numproveedor = 'PROV-REF-001';
    public $fecha;
    public $fechadevengo;
    public $finoferta;
    public $nombrecliente = 'Cliente de Ejemplo S.L.';
    public $cifnif = 'B12345678';
    public $codcliente = '000';
    public $codagente = 'AGT';
    public $codpago = 'CONT';
    public $direccion = 'Calle de Ejemplo, 1';
    public $codpostal = '28001';
    public $ciudad = 'Madrid';
    public $provincia = 'Madrid';
    public $idcontactofact = 1;
    public $idcontactoenv = 2;
    public $codigoenv = 'ENV-2026-0001';
    public $codtrans = 'STD';
    public $coddivisa = '';
    public $editable = true;
    public $observaciones = 'Documento de muestra para previsualizar el estilo. Las líneas, importes y datos son de ejemplo.';

    // Totales (calculados en el constructor a partir de las líneas)
    public $netosindto = 0.0;
    public $neto = 0.0;
    public $totaliva = 0.0;
    public $totalrecargo = 0.0;
    public $totalirpf = 0.0;
    public $totalsuplidos = 0.0;
    public $total = 0.0;

    /** @var object[] */
    private $lines = [];
    private $receipts = [];
    private $modelClassName = 'FacturaCliente';
    private $documentTitle = '';
    private $subject;
    public $shippingAddress;

    public function __construct(?int $idempresa = null, string $modelClassName = 'FacturaCliente', string $documentTitle = '')
    {
        $this->idempresa = $idempresa;
        $this->modelClassName = $this->normalizeModelClassName($modelClassName);
        $this->documentTitle = trim($documentTitle);
        $this->applyDocumentDefaults();

        $this->fecha = date('d-m-Y');
        $this->fechadevengo = date('d-m-Y');
        $this->finoferta = date('d-m-Y', strtotime('+30 days'));

        $this->subject = (object) [
            'cifnif' => $this->cifnif,
            'telefono1' => '910 000 000',
            'telefono2' => '620 000 000',
            'email' => 'cliente@example.test',
        ];

        $this->shippingAddress = (object) [
            'direccion' => 'Avenida de Entrega, 25',
            'codpostal' => '28002',
            'ciudad' => 'Madrid',
            'provincia' => 'Madrid',
        ];

        $this->lines = [
            $this->line('REF-001', 'Producto de ejemplo A', 2.0, 25.0, 0.0, 50.0, 21.0),
            $this->line('REF-002', 'Servicio profesional de ejemplo B', 1.0, 40.0, 10.0, 36.0, 21.0, 0.0, 15.0),
            $this->line('REF-003', 'Artículo de ejemplo C con descripción algo más larga', 3.0, 12.0, 0.0, 36.0, 10.0, 5.2),
        ];

        // Totales coherentes con las líneas
        $neto = 0.0;
        $iva = 0.0;
        $recargo = 0.0;
        $irpf = 0.0;
        foreach ($this->lines as $l) {
            $neto += (float) $l->pvptotal;
            $iva += (float) $l->pvptotal * ((float) $l->iva / 100.0);
            $recargo += (float) $l->pvptotal * ((float) $l->recargo / 100.0);
            $irpf += (float) $l->pvptotal * ((float) $l->irpf / 100.0);
        }
        $this->neto = round($neto, 2);
        $this->netosindto = $this->neto;
        $this->totaliva = round($iva, 2);
        $this->totalrecargo = round($recargo, 2);
        $this->totalirpf = round($irpf, 2);
        $this->total = round($neto + $iva + $recargo - $irpf, 2);

        $this->receipts = [
            (object) [
                'numero' => '1',
                'importe' => round($this->total / 2, 2),
                'vencimiento' => date('d-m-Y', strtotime('+15 days')),
                'pagado' => false,
                'codpago' => $this->codpago,
            ],
            (object) [
                'numero' => '2',
                'importe' => round($this->total / 2, 2),
                'vencimiento' => date('d-m-Y', strtotime('+30 days')),
                'pagado' => false,
                'codpago' => $this->codpago,
            ],
        ];
    }

    /** Igual que un BusinessDocument: devuelve las líneas del documento. */
    public function getLines(): array
    {
        return $this->lines;
    }

    /** Nombre de la clase de modelo (los renderers lo usan para el título y tipo). */
    public function modelClassName(): string
    {
        return $this->modelClassName;
    }

    public function beplyPdfDocumentTitle(): string
    {
        return $this->documentTitle;
    }

    public function beplyPdfIsSamplePreview(): bool
    {
        return true;
    }

    public function beplyPdfPreviewNotice(): string
    {
        return $this->modelClassName === 'FacturaCliente'
            ? self::PREVIEW_INVOICE_NOTICE
            : self::PREVIEW_DOCUMENT_NOTICE;
    }

    public function getSubject()
    {
        return $this->subject;
    }

    public function getReceipts(): array
    {
        return $this->receipts;
    }

    /** Crea un objeto línea con la superficie que leen los renderers. */
    private function line(string $ref, string $desc, float $cant, float $pvp, float $dto, float $pvptotal, float $iva, float $recargo = 0.0, float $irpf = 0.0): object
    {
        $l = new \stdClass();
        $l->referencia = $ref;
        $l->descripcion = $desc;
        $l->cantidad = $cant;
        $l->pvpunitario = $pvp;
        $l->dtopor = $dto;
        $l->pvptotal = $pvptotal;
        $l->iva = $iva;
        $l->recargo = $recargo;
        $l->irpf = $irpf;
        return $l;
    }

    private function normalizeModelClassName(string $modelClassName): string
    {
        $valid = ['FacturaCliente', 'PresupuestoCliente', 'PedidoCliente', 'AlbaranCliente'];
        return in_array($modelClassName, $valid, true) ? $modelClassName : 'FacturaCliente';
    }

    private function applyDocumentDefaults(): void
    {
        if ($this->modelClassName === 'FacturaCliente') {
            return;
        }

        $prefix = [
            'PresupuestoCliente' => 'PRE',
            'PedidoCliente' => 'PED',
            'AlbaranCliente' => 'ALB',
        ][$this->modelClassName] ?? 'DOC';

        $this->codigo = $prefix . '-2026-0001';
        $this->numero = $prefix . '-0001';
        $this->numero2 = 'EXT-' . $prefix . '-42';
    }
}
