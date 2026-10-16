<?php
/**
 * This file is part of BeplyPDFStudio plugin for FacturaScripts
 * Copyright (C) 2026 Beply Technologies S.L.
 */

namespace FacturaScripts\Test\Plugins;

use PHPUnit\Framework\TestCase;

/** Documentos del chat (modo Documentos e informes de auditoría) renderizados por PDF Studio. */
final class ChatDocumentTest extends TestCase
{
    public function testSpecificationAndService(): void
    {
        require __DIR__ . '/ChatDocument/spec-cases.php';
        $this->addToAssertionCount(1);
    }

    public function testToolProvider(): void
    {
        require __DIR__ . '/ChatDocument/provider-cases.php';
        $this->addToAssertionCount(1);
    }

    public function testDocumentHtml(): void
    {
        require __DIR__ . '/ChatDocument/html-cases.php';
        $this->addToAssertionCount(1);
    }

    public function testTemplateUsesTheConfiguredStyle(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__) . '/Templates/html/chat-document.html.twig');
        foreach (['{{ page_size }}', '{{ page_margin }}', '{{ color1 }}', '{{ font_faces|raw }}', '{{ logo }}', '{{ page_footer_content|raw }}', '{{ body_html|raw }}'] as $marker) {
            $this->assertStringContainsString($marker, $twig);
        }
        $service = (string) file_get_contents(dirname(__DIR__) . '/Lib/Html/BeplyHtmlRenderService.php');
        $this->assertStringContainsString('public function renderChatDocument(BeplyPdfConfig $cfg, array $spec, ?int $idempresa = null): string', $service);
    }
}
