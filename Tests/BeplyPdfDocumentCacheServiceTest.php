<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfConfig;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfDocumentCacheService;
use PHPUnit\Framework\TestCase;

final class BeplyPdfDocumentCacheServiceTest extends TestCase
{
    public function testSupportsSalesAndPurchaseDocuments(): void
    {
        $service = new BeplyPdfDocumentCacheService();
        foreach ([
            'PresupuestoCliente',
            'PedidoCliente',
            'AlbaranCliente',
            'FacturaCliente',
            'PresupuestoProveedor',
            'PedidoProveedor',
            'AlbaranProveedor',
            'FacturaProveedor',
        ] as $modelClass) {
            $doc = $this->document();
            $doc->className = $modelClass;
            $this->assertTrue($service->supports($doc), $modelClass);
        }
    }

    public function testHashIsStableForSameDocumentPayload(): void
    {
        $service = new BeplyPdfDocumentCacheService();
        $config = new BeplyPdfConfig();
        $doc = $this->document();

        $this->assertSame(
            $service->debugHash($config, $doc),
            $service->debugHash($config, $doc)
        );
    }

    public function testHashChangesWhenLineChanges(): void
    {
        $service = new BeplyPdfDocumentCacheService();
        $config = new BeplyPdfConfig();
        $doc = $this->document();
        $before = $service->debugHash($config, $doc);

        $doc->lines[0]->descripcion = 'Servicio de consultoria ampliado';

        $this->assertTrue($before !== $service->debugHash($config, $doc));
    }

    public function testHashChangesWhenReceiptChanges(): void
    {
        $service = new BeplyPdfDocumentCacheService();
        $config = new BeplyPdfConfig();
        $doc = $this->document();
        $before = $service->debugHash($config, $doc);

        $doc->receipts[0]->importe = 999.99;

        $this->assertTrue($before !== $service->debugHash($config, $doc));
    }

    public function testHashChangesWhenConfigChanges(): void
    {
        $service = new BeplyPdfDocumentCacheService();
        $config = new BeplyPdfConfig();
        $doc = $this->document();
        $before = $service->debugHash($config, $doc);

        $config->footerText = '**Condiciones legales actualizadas**';

        $this->assertTrue($before !== $service->debugHash($config, $doc));
    }

    public function testHashChangesWhenPrintableAttachmentContentChanges(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bps-doc-cache-');
        file_put_contents($path, '%PDF-1.4 original');

        $service = new BeplyPdfDocumentCacheService();
        $config = new BeplyPdfConfig();
        $doc = $this->document();
        $doc->attachments = [new DocumentCacheFakeAttachmentRelation($path)];
        $before = $service->debugHash($config, $doc);

        file_put_contents($path, '%PDF-1.4 changed');

        $this->assertTrue($before !== $service->debugHash($config, $doc));
        @unlink($path);
    }

    public function testStoreGetAndPruneDocumentPdf(): void
    {
        $service = new BeplyPdfDocumentCacheService();
        $config = new BeplyPdfConfig();
        $doc = $this->document();
        $first = $service->key($config, $doc);
        $this->assertNotNull($first);
        $this->clearCacheDir($first['path']);

        $this->assertTrue($service->store($first, '%PDF-1.4 first'));
        $this->assertSame('%PDF-1.4 first', $service->get($first));

        $doc->observaciones = 'Texto distinto';
        $second = $service->key($config, $doc);
        $this->assertNotNull($second);
        $this->assertTrue($service->store($second, '%PDF-1.4 second'));

        $this->assertFalse(is_file($first['path']));
        $this->assertSame('%PDF-1.4 second', $service->get($second));
        $this->clearCacheDir($second['path']);
    }

    private function document(): DocumentCacheFakeDocument
    {
        $doc = new DocumentCacheFakeDocument();
        $doc->idfactura = 6;
        $doc->codigo = 'FAC-TEST-6';
        $doc->idempresa = 1;
        $doc->codcliente = 'CLI';
        $doc->coddivisa = 'EUR';
        $doc->codpago = 'TRANS';
        $doc->observaciones = 'Observaciones';
        $doc->lines = [
            new DocumentCacheFakeLine(1, 'CONS', 'Servicio de consultoria', 390.0),
        ];
        $doc->receipts = [
            new DocumentCacheFakeReceipt(1, 390.0, '2026-08-02'),
        ];
        return $doc;
    }

    private function clearCacheDir(string $path): void
    {
        $dir = dirname($path);
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}

final class DocumentCacheFakeDocument
{
    public string $className = 'FacturaCliente';
    public int $idfactura = 0;
    public string $codigo = '';
    public int $idempresa = 0;
    public string $codcliente = '';
    public string $coddivisa = '';
    public string $codpago = '';
    public string $observaciones = '';
    /** @var DocumentCacheFakeLine[] */
    public array $lines = [];
    /** @var DocumentCacheFakeReceipt[] */
    public array $receipts = [];
    /** @var DocumentCacheFakeAttachmentRelation[] */
    public array $attachments = [];

    public function modelClassName(): string
    {
        return $this->className;
    }

    public static function tableName(): string
    {
        return 'facturascli';
    }

    public static function primaryColumn(): string
    {
        return 'idfactura';
    }

    public function id(): int
    {
        return $this->idfactura;
    }

    public function getModelFields(): array
    {
        return ['idfactura', 'codigo', 'idempresa', 'codcliente', 'coddivisa', 'codpago', 'observaciones'];
    }

    public function getLines(): array
    {
        return $this->lines;
    }

    public function getReceipts(): array
    {
        return $this->receipts;
    }

    public function getAttachedFiles(): array
    {
        return $this->attachments;
    }
}

final class DocumentCacheFakeLine
{
    public function __construct(
        public int $idlinea,
        public string $referencia,
        public string $descripcion,
        public float $pvptotal
    ) {
    }

    public function modelClassName(): string
    {
        return 'LineaFacturaCliente';
    }

    public static function tableName(): string
    {
        return 'lineasfacturascli';
    }

    public function id(): int
    {
        return $this->idlinea;
    }

    public function getModelFields(): array
    {
        return ['idlinea', 'referencia', 'descripcion', 'pvptotal'];
    }
}

final class DocumentCacheFakeReceipt
{
    public function __construct(
        public int $idrecibo,
        public float $importe,
        public string $vencimiento
    ) {
        $this->codpago = 'TRANS';
    }

    public string $codpago;

    public function modelClassName(): string
    {
        return 'ReciboCliente';
    }

    public static function tableName(): string
    {
        return 'reciboscli';
    }

    public function id(): int
    {
        return $this->idrecibo;
    }

    public function getModelFields(): array
    {
        return ['idrecibo', 'importe', 'vencimiento', 'codpago'];
    }
}

final class DocumentCacheFakeAttachmentRelation
{
    public int $id = 1;
    public int $idfile = 10;
    public string $model = 'FacturaCliente';
    public int $modelid = 6;
    public bool $beply_pdf_print = true;

    public function __construct(private string $path)
    {
    }

    public function modelClassName(): string
    {
        return 'AttachedFileRelation';
    }

    public static function tableName(): string
    {
        return 'attached_files_rel';
    }

    public function id(): int
    {
        return $this->id;
    }

    public function getModelFields(): array
    {
        return ['id', 'idfile', 'model', 'modelid', 'beply_pdf_print'];
    }

    public function getFile(): DocumentCacheFakeAttachedFile
    {
        return new DocumentCacheFakeAttachedFile($this->path);
    }
}

final class DocumentCacheFakeAttachedFile
{
    public int $idfile = 10;
    public string $filename = 'attachment.pdf';
    public string $mimetype = 'application/pdf';

    public function __construct(private string $path)
    {
    }

    public function modelClassName(): string
    {
        return 'AttachedFile';
    }

    public static function tableName(): string
    {
        return 'attached_files';
    }

    public function id(): int
    {
        return $this->idfile;
    }

    public function getModelFields(): array
    {
        return ['idfile', 'filename', 'mimetype'];
    }

    public function getFullPath(): string
    {
        return $this->path;
    }
}
