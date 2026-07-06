<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 */

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfInternalFormatPolicy;
use PHPUnit\Framework\TestCase;

final class BeplyPdfInternalFormatPolicyTest extends TestCase
{
    public function testNormalizesUnknownScopeToNone(): void
    {
        $this->assertSame(BeplyPdfInternalFormatPolicy::SCOPE_NONE, BeplyPdfInternalFormatPolicy::normalizeScope('bad'));
    }

    public function testSalesInvoiceScopeOnlyForCustomerInvoices(): void
    {
        $rule = [
            'force_draft_warning' => true,
            'draft_warning_scope' => BeplyPdfInternalFormatPolicy::SCOPE_SALES_INVOICES,
        ];

        $this->assertTrue(BeplyPdfInternalFormatPolicy::shouldForceDraftWarning($rule, 'FacturaCliente'));
        $this->assertFalse(BeplyPdfInternalFormatPolicy::shouldForceDraftWarning($rule, 'PresupuestoCliente'));
        $this->assertFalse(BeplyPdfInternalFormatPolicy::shouldForceDraftWarning($rule, 'FacturaProveedor'));
    }

    public function testAllDocumentsScopeForcesWarning(): void
    {
        $rule = [
            'force_draft_warning' => true,
            'draft_warning_scope' => BeplyPdfInternalFormatPolicy::SCOPE_ALL_DOCUMENTS,
        ];

        $this->assertTrue(BeplyPdfInternalFormatPolicy::shouldForceDraftWarning($rule, 'PedidoCliente'));
    }

    public function testDisabledRuleDoesNotForceWarning(): void
    {
        $rule = [
            'force_draft_warning' => false,
            'draft_warning_scope' => BeplyPdfInternalFormatPolicy::SCOPE_ALL_DOCUMENTS,
        ];

        $this->assertFalse(BeplyPdfInternalFormatPolicy::shouldForceDraftWarning($rule, 'FacturaCliente'));
    }
}
