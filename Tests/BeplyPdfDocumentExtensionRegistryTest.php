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
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentBlock;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionInterface;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentSlot;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfFiscalQrBlockData;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfFiscalQrProviderInterface;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfFiscalQrRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineColumn;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfLineColumnProviderInterface;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfReceiptInfoProviderInterface;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\PdfEngine\BeplyPdfSampleDoc;
use PHPUnit\Framework\TestCase;

final class BeplyPdfDocumentExtensionRegistryTest extends TestCase
{
    public function testBlocksAreFilteredAndSortedByZone(): void
    {
        BeplyPdfDocumentExtensionRegistry::clear();
        BeplyPdfDocumentExtensionRegistry::addExtension(new class implements BeplyPdfDocumentExtensionInterface {
            public function blocks(BeplyPdfDocumentContext $context): array
            {
                return [
                    BeplyPdfDocumentBlock::html('header.extra', '<span>late</span>', 'Late', 200),
                    BeplyPdfDocumentBlock::html('lines.after', '<span>ignored</span>', 'Ignored', 100),
                    BeplyPdfDocumentBlock::html('header.extra', '<span>first</span>', 'First', 10),
                ];
            }
        });

        $blocks = BeplyPdfDocumentExtensionRegistry::blocksFor(
            'header.extra',
            new BeplyPdfDocumentContext(new BeplyPdfConfig(), (object) ['codigo' => 'T-1'])
        );

        $this->assertCount(2, $blocks);
        $this->assertSame('First', $blocks[0]->title);
        $this->assertSame('Late', $blocks[1]->title);
        BeplyPdfDocumentExtensionRegistry::clear();
    }

    public function testTemplateSlotsAreStableForFutureDesigns(): void
    {
        $slots = BeplyPdfDocumentSlot::templateSlots();
        $this->assertContains(BeplyPdfDocumentSlot::DOCUMENT_META_AFTER, $slots);
        $this->assertContains(BeplyPdfDocumentSlot::PARTY_CUSTOMER_AFTER, $slots);
        $this->assertContains(BeplyPdfDocumentSlot::LINES_BEFORE, $slots);
        $this->assertContains(BeplyPdfDocumentSlot::LINES_AFTER, $slots);
        $this->assertContains(BeplyPdfDocumentSlot::TOTALS_BEFORE, $slots);
        $this->assertContains(BeplyPdfDocumentSlot::RECEIPTS_AFTER, $slots);
        $this->assertContains(BeplyPdfDocumentSlot::FISCAL_FOOTER, $slots);
    }

    public function testBaseCopyTemplateContainsEverySlot(): void
    {
        $path = dirname(__DIR__) . '/Templates/html/_base-copy-template.html.twig';
        $this->assertTrue(is_file($path), 'Base copy template missing');

        $template = (string) file_get_contents($path);
        foreach (BeplyPdfDocumentSlot::templateSlots() as $slot) {
            $slotKey = strtoupper(str_replace(['.', '-'], '_', $slot));
            $this->assertTrue(strpos($template, 'slots.' . $slotKey) !== false, 'Missing slot ' . $slot);
        }
    }

    public function testDocumentExtensionDocsExist(): void
    {
        $this->assertTrue(is_file(dirname(__DIR__) . '/docs/document-extension-api.md'), 'Document extension docs missing');
    }

    public function testFiscalQrBlockUsesLegalSizeAndFiscalSlot(): void
    {
        $block = BeplyPdfDocumentBlock::fiscalQr(new BeplyPdfFiscalQrBlockData(
            'ticketbai',
            'TicketBAI',
            'data:image/png;base64,AA==',
            [
                ['label' => 'Codigo TicketBAI', 'value' => 'TBAI-00000006Y-251019-btFpwP8dcLGAF-237'],
                ['label' => 'Firmado', 'value' => '2026-07-02 10:00:00'],
            ],
            '',
            99,
            'landscape',
            'TicketBAI QR'
        ));

        $this->assertSame(BeplyPdfDocumentSlot::FISCAL_FOOTER, $block->slot);
        $this->assertSame('', $block->title);
        $this->assertTrue(strpos($block->html, 'data-beply-fiscal-system="ticketbai"') !== false, 'Fiscal system marker missing');
        $this->assertTrue(strpos($block->html, 'width:40mm;height:40mm') !== false, 'QR size was not clamped to 40mm');
        $this->assertTrue(strpos($block->html, 'margin-left:auto;margin-right:0') !== false, 'Landscape block is not aligned right');
        $this->assertTrue(strpos($block->html, 'Codigo TicketBAI') !== false, 'Fiscal row label missing');
    }

    public function testFiscalQrRegistryRendersNativePdfStudioBlocksFromProviders(): void
    {
        BeplyPdfFiscalQrRegistry::clear();
        BeplyPdfFiscalQrRegistry::addProvider(new class implements BeplyPdfFiscalQrProviderInterface {
            public function fiscalQr(BeplyPdfDocumentContext $context): ?BeplyPdfFiscalQrBlockData
            {
                return new BeplyPdfFiscalQrBlockData(
                    'verifactu',
                    'VERI*FACTU',
                    'data:image/png;base64,AA==',
                    [['label' => 'URL', 'value' => 'https://www2.agenciatributaria.gob.es/...']],
                    'Factura verificable en la sede electronica de la AEAT',
                    30,
                    'portrait',
                    'VERI*FACTU QR',
                    600
                );
            }
        });

        $blocks = BeplyPdfFiscalQrRegistry::blocksFor(new BeplyPdfDocumentContext(
            new BeplyPdfConfig(),
            (object) ['codigo' => 'FV-1']
        ));

        $this->assertCount(1, $blocks);
        $this->assertSame(BeplyPdfDocumentSlot::FISCAL_FOOTER, $blocks[0]->slot);
        $this->assertSame(600, $blocks[0]->priority);
        $this->assertTrue(strpos($blocks[0]->html, 'data-beply-fiscal-system="verifactu"') !== false, 'VERI*FACTU marker missing');
        $this->assertTrue(strpos($blocks[0]->html, 'Factura verificable') !== false, 'VERI*FACTU legal notice missing');
        BeplyPdfFiscalQrRegistry::clear();
    }

    public function testSamplePreviewDocumentCarriesNonRealInvoiceWarning(): void
    {
        $doc = new BeplyPdfSampleDoc(null, 'FacturaCliente');

        $this->assertTrue($doc->beplyPdfIsSamplePreview());
        $this->assertSame('ESTA FACTURA ES 100% DE PRUEBA Y NO ES REAL', $doc->beplyPdfPreviewNotice());
    }

    public function testPublishedDesignTemplatesExposeFiscalFooterSlot(): void
    {
        $templates = ['standard', 'summary', 'boxes', 'framed', 'banner', 'corporate', 'azure', 'prisma', 'studio-quote'];
        foreach ($templates as $template) {
            $path = dirname(__DIR__) . '/Templates/html/' . $template . '.html.twig';
            $this->assertTrue(is_file($path), 'Missing template ' . $template);
            $body = (string) file_get_contents($path);
            $this->assertTrue(strpos($body, 'slots.FISCAL_FOOTER') !== false, 'Fiscal footer slot missing in ' . $template);
        }
    }

    public function testExternalLineColumnsAreSortedAndRenderable(): void
    {
        BeplyPdfDocumentExtensionRegistry::clear();
        BeplyPdfDocumentExtensionRegistry::addLineColumnProvider(new class implements BeplyPdfLineColumnProviderInterface {
            public function lineColumns(BeplyPdfDocumentContext $context): array
            {
                return [
                    BeplyPdfLineColumn::make('late', 'Late', static fn($line, int $number): string => 'L' . $number, 'right', 200),
                    BeplyPdfLineColumn::make('first', 'First', static fn($line, int $number): string => 'F' . $number, 'left', 10),
                ];
            }
        });

        $columns = BeplyPdfDocumentExtensionRegistry::lineColumnsFor(
            new BeplyPdfDocumentContext(new BeplyPdfConfig(), (object) ['codigo' => 'T-3'])
        );

        $this->assertCount(2, $columns);
        $this->assertSame('first', $columns[0]->key);
        $this->assertSame('late', $columns[1]->key);
        $this->assertSame('F7', $columns[0]->render((object) [], 7, new BeplyPdfDocumentContext(new BeplyPdfConfig(), (object) [])));
        BeplyPdfDocumentExtensionRegistry::clear();
    }

    public function testReceiptInfoProviderReturnsFirstNonEmptyValue(): void
    {
        BeplyPdfDocumentExtensionRegistry::clear();
        BeplyPdfDocumentExtensionRegistry::addReceiptInfoProvider(new class implements BeplyPdfReceiptInfoProviderInterface {
            public function receiptInfo(BeplyPdfDocumentContext $context, object $receipt, array $receipts): ?string
            {
                return null;
            }
        });
        BeplyPdfDocumentExtensionRegistry::addReceiptInfoProvider(new class implements BeplyPdfReceiptInfoProviderInterface {
            public function receiptInfo(BeplyPdfDocumentContext $context, object $receipt, array $receipts): ?string
            {
                return 'DOMICILIADO<br/>IBAN **** 1234';
            }
        });

        $info = BeplyPdfDocumentExtensionRegistry::receiptInfo(
            new BeplyPdfDocumentContext(new BeplyPdfConfig(), (object) ['codigo' => 'T-2']),
            (object) ['numero' => '1'],
            []
        );

        $this->assertSame('DOMICILIADO<br/>IBAN **** 1234', $info);
        BeplyPdfDocumentExtensionRegistry::clear();
    }
}
