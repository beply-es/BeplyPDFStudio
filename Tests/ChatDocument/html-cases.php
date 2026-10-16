<?php

declare(strict_types=1);

/** Contenido HTML de los documentos del chat (lo maqueta chat-document.html.twig). */

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\ChatDocument\DocumentHtmlRenderer;

require_once __DIR__ . '/asserts.php';

$html = DocumentHtmlRenderer::blocks([
    ['type' => 'paragraph', 'text' => 'Firmado por las partes.'],
    ['type' => 'signature', 'name' => 'Ana López', 'role' => 'Beply'],
    ['type' => 'signature', 'name' => 'Luis Pérez', 'role' => 'Cliente'],
    ['type' => 'paragraph', 'text' => 'Fin.'],
    ['type' => 'signature', 'name' => 'Solo'],
]);
same(1, substr_count($html, '<table class="bd-signatures">'), 'Consecutive signatures share a row (contracts).');
same(3, substr_count($html, 'class="bd-signature"'), 'Every signature is rendered.');
check(strpos($html, 'Fin.') > strpos($html, 'bd-signatures'), 'Order is kept.');

$escaped = DocumentHtmlRenderer::blocks([['type' => 'paragraph', 'text' => '<script>alert(1)</script> **negrita**']]);
check(false === str_contains($escaped, '<script>'), 'Model text is always escaped.');
check(str_contains($escaped, '<strong>negrita</strong>'), 'Inline bold is kept.');

$table = DocumentHtmlRenderer::blocks([['type' => 'table', 'header' => ['Concepto', 'Importe'], 'rows' => [['Cuota', '1.200,00 €']]]]);
check(str_contains($table, '<td class="bd-num">1.200,00 €</td>'), 'Money columns align right.');

$opening = DocumentHtmlRenderer::opening(['title' => 'Contrato', 'meta' => ['place' => 'Valencia', 'date' => '2026-10-16', 'subject' => 'Servicios']]);
check(str_contains($opening, 'Valencia, 16 de octubre de 2026'), 'Place and date in Spanish.');
check(str_contains($opening, '<h1 class="bd-title">Contrato</h1>'), 'Title.');
