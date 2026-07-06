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

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\BeplyPdfRichTextLite;
use PHPUnit\Framework\TestCase;

final class BeplyPdfRichTextLiteTest extends TestCase
{
    public function testPlainTextIsNotMarkedAsRich(): void
    {
        $this->assertFalse(BeplyPdfRichTextLite::hasMarkup('Servicio de mantenimiento mensual'));
        $this->assertSame('Servicio de mantenimiento mensual', BeplyPdfRichTextLite::toFallbackText('Servicio de mantenimiento mensual'));
        $this->assertSame('Servicio de mantenimiento mensual', BeplyPdfRichTextLite::toDisplayText('Servicio de mantenimiento mensual'));
    }

    public function testLegacyHeadingsRenderAsNormalTextWithInlineStyles(): void
    {
        $text = "### Alcance\n- **Instalacion** inicial\n- Soporte *prioritario*";
        $html = BeplyPdfRichTextLite::toHtml($text);

        $this->assertTrue(BeplyPdfRichTextLite::hasMarkup($text));
        $this->assertSame(
            '<div class="beply-rich-desc" style="font-size:inherit;line-height:1.25"><p style="margin:0 0 2px 0">Alcance</p><ul style="margin:2px 0 2px 1.2em;padding-left:1.1em"><li style="margin:0 0 1px 0"><strong style="font-weight:650">Instalacion</strong> inicial</li><li style="margin:0 0 1px 0">Soporte <em style="font-style:italic">prioritario</em></li></ul></div>',
            $html
        );
        $this->assertSame('Alcance Instalacion inicial Soporte prioritario', BeplyPdfRichTextLite::toDisplayText($text));
    }

    public function testHtmlInputStaysEscaped(): void
    {
        $text = "### <script>alert('x')</script>";

        $this->assertSame(
            '<div class="beply-rich-desc" style="font-size:inherit;line-height:1.25"><p style="margin:0 0 2px 0">&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;</p></div>',
            BeplyPdfRichTextLite::toHtml($text)
        );
        $this->assertSame("<script>alert('x')</script>", BeplyPdfRichTextLite::toFallbackText($text));
    }

    public function testInternalUnderscoresAreNotItalicMarkdown(): void
    {
        $text = 'E2E_FORMAT_FOOTER_TEXT y _cursiva_';

        $this->assertTrue(BeplyPdfRichTextLite::hasMarkup($text));
        $this->assertSame(
            '<div class="beply-rich-desc" style="font-size:inherit;line-height:1.25"><p style="margin:0 0 2px 0">E2E_FORMAT_FOOTER_TEXT y <em style="font-style:italic">cursiva</em></p></div>',
            BeplyPdfRichTextLite::toHtml($text)
        );
        $this->assertSame('E2E_FORMAT_FOOTER_TEXT y cursiva', BeplyPdfRichTextLite::toDisplayText($text));
    }
}
