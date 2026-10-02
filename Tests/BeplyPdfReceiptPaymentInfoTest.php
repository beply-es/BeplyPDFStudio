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
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentContext;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfDocumentExtensionRegistry;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfReceiptInfoProviderInterface;
use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document\BeplyPdfReceiptPaymentInfo;
use PHPUnit\Framework\TestCase;

final class ReceiptPaymentInfoProbe extends BeplyPdfReceiptPaymentInfo
{
    public object $payment;
    protected function paymentMethod(string $code): ?object { return $this->payment; }
    protected function ibanLabel(): string { return 'IBAN'; }
}

final class BeplyPdfReceiptPaymentInfoTest extends TestCase
{
    public function testDomiciledNeverUsesCompanyBankWithOrWithoutCustomerData(): void
    {
        $service = $this->service();
        $model = $this->model();
        $this->assertSame('Domiciliado', $service->text($model, (object)[]));
        $this->assertSame('Domiciliado - IBAN: DE89 **** **** **** 3000', $service->text($model, (object)['iban'=>'DE89370400440532013000']));
        $model->customer->accounts = [(object)['codcliente'=>'C71','codcuenta'=>'2','principal'=>true,'iban'=>'ES7921000813610123456789']];
        $this->assertSame('Domiciliado - IBAN: ES79 **** **** **** 6789', $service->text($model, (object)[]));
        $this->assertSame('Domiciliado - IBAN: DE89 **** **** **** 3000', $service->text($model, (object)['iban'=>'DE89370400440532013000']));
    }

    public function testAccountsUsePrincipalThenCodeAndRejectForeignOrEmptyAccounts(): void
    {
        $model=$this->model();
        $model->customer->accounts = [
            (object)['codcliente'=>'C71','codcuenta'=>'3','principal'=>false,'iban'=>'DE89370400440532013000'],
            (object)['codcliente'=>'FOREIGN','codcuenta'=>'0','principal'=>true,'iban'=>'ES9121000418450200051332'],
            (object)['codcliente'=>'C71','codcuenta'=>'0','principal'=>true,'iban'=>''],
            (object)['codcliente'=>'C71','codcuenta'=>'2','principal'=>true,'iban'=>'ES7921000813610123456789'],
            (object)['codcliente'=>'C71','codcuenta'=>'1','principal'=>true,'iban'=>'DE89370400440532013000'],
        ];
        $this->assertSame(['1','2','3'], array_map(static fn($a): string => $a->codcuenta, BeplyPdfReceiptPaymentInfo::customerAccounts($model)));
    }

    public function testImprimirAndProviderCellOwnership(): void
    {
        $service=$this->service();$model=$this->model();$receipt=(object)[];
        $context=new BeplyPdfDocumentContext(new BeplyPdfConfig(), $model);
        BeplyPdfDocumentExtensionRegistry::clear();
        try {
            BeplyPdfDocumentExtensionRegistry::addReceiptInfoProvider(new class implements BeplyPdfReceiptInfoProviderInterface {
                public function receiptInfo(BeplyPdfDocumentContext $context, object $receipt, array $receipts): ?string { return "Provider <unsafe>\nSecond line"; }
            });
            $this->assertSame("Provider <unsafe>\nSecond line", $service->text($model,$receipt,$context,[$receipt]));
            $service->payment->imprimir=false;
            $this->assertSame('', $service->text($model,$receipt,$context,[$receipt]));
        } finally { BeplyPdfDocumentExtensionRegistry::clear(); }
    }

    public function testCustomerBankChangesInvalidateDocumentCache(): void
    {
        $model=$this->model();$cache=new BeplyPdfDocumentCacheService();$config=new BeplyPdfConfig();
        $model->customer->accounts=[(object)['codcliente'=>'C71','codcuenta'=>'1','principal'=>true,'iban'=>'ES7921000813610123456789']];
        $before=$cache->debugHash($config,$model);
        $this->assertTrue($before !== '', 'Cache must be available for the document');
        $model->customer->accounts[0]->iban='DE89370400440532013000';
        $this->assertTrue($before !== $cache->debugHash($config,$model));
        $before=$cache->debugHash($config,$model);
        $model->customer->accounts[0]->principal=false;
        $this->assertTrue($before !== $cache->debugHash($config,$model));
    }

    private function service(): ReceiptPaymentInfoProbe
    {
        $service=new ReceiptPaymentInfoProbe();
        $service->payment=new class {
            public bool $imprimir=true;
            public bool $domiciliado=true;
            public string $descripcion='Domiciliado';
            public function getBankAccount(): object { throw new \RuntimeException('Company bank must never be accessed for direct debit'); }
        };
        return $service;
    }

    private function model(): object
    {
        return new class {
            public string $codigo='SYNTHETIC71';
            public string $codcliente='C71';
            public string $codpago='SEPA';
            public object $customer;
            public function __construct() { $this->customer=new class { public array $accounts=[]; public function getBankAccounts(): array { return $this->accounts; } }; }
            public function getSubject(): object { return $this->customer; }
            public function modelClassName(): string { return 'FacturaCliente'; }
            public function getLines(): array { return []; }
            public function getReceipts(): array { return []; }
        };
    }
}
