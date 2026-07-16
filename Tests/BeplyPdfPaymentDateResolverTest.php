<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfPaymentDateResolver;
use PHPUnit\Framework\TestCase;

final class BeplyPdfPaymentDateResolverTest extends TestCase
{
    public function testUnpaidInvoiceDoesNotUseAccrualDateAsPaymentDate(): void
    {
        $model = new class {
            public string $fechadevengo = '2026-07-09';

            public function getReceipts(): array
            {
                return [(object) [
                    'pagado' => false,
                    'fechapago' => null,
                    'vencimiento' => '2026-08-08',
                ]];
            }
        };

        $this->assertSame('', BeplyPdfPaymentDateResolver::resolve($model));
    }

    public function testPaidInvoiceUsesReceiptPaymentDate(): void
    {
        $model = new class {
            public string $fechadevengo = '2026-07-09';

            public function getReceipts(): array
            {
                return [(object) [
                    'pagado' => true,
                    'fechapago' => '2026-08-05',
                    'vencimiento' => '2026-08-08',
                ]];
            }
        };

        $this->assertSame('2026-08-05', BeplyPdfPaymentDateResolver::resolve($model));
    }

    public function testPartiallyPaidInvoiceDoesNotClaimASettlementDate(): void
    {
        $model = new class {
            public function getReceipts(): array
            {
                return [
                    (object) ['pagado' => true, 'fechapago' => '2026-08-05'],
                    (object) ['pagado' => false, 'fechapago' => null],
                ];
            }
        };

        $this->assertSame('', BeplyPdfPaymentDateResolver::resolve($model));
    }

    public function testFullyPaidInstallmentsUseLatestReceiptPaymentDate(): void
    {
        $model = new class {
            public function getReceipts(): array
            {
                return [
                    (object) ['pagado' => true, 'fechapago' => '2026-08-05'],
                    (object) ['pagado' => true, 'fechapago' => '2026-09-05'],
                ];
            }
        };

        $this->assertSame('2026-09-05', BeplyPdfPaymentDateResolver::resolve($model));
    }
}
