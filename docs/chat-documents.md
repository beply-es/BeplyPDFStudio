# Modo Documentos e informe de auditoría (`documents_canvas_v1`)

Estado: v1 en BeplyPDFStudio (rama `claude/beplypdfstudio-chat-documents-20261016`).
Desde 2026-10-16 el modo Documentos vive en PDF Studio, como los informes viven en
BeplyInformes: aquí están la especificación, el almacén, las tools y el render con
la plantilla configurada. BeplyChatAI sólo aporta el visor y anuncia la capacidad.
Este documento es la fuente de verdad del contrato entre PDF Studio, el chat, Node y
el ADK.

## Objetivo

- **Modo Documentos** (sólo con PDF Studio habilitado): el
  usuario le dicta a Bep qué escribir y ve a la derecha, en el visor de
  artefactos, la vista previa del documento, que Bep reescribe turno a turno.
  Al final lo descarga en **PDF** o **DOCX**. Son documentos «de verdad» (cartas,
  contratos, propuestas, memorias, actas...); los correos siguen con su borrador.
- **Informe de auditoría**: al terminar una auditoría, Bep guarda un informe en
  lenguaje humano que se adjunta al mensaje como tarjeta («Abrir») y **no se abre
  solo** (`open:false`).
- Diseño: el estilo de PDF Studio de la empresa (o el global) — papel, márgenes,
  logo, colores, fuente, pie y numeración — con su motor HTML → WeasyPrint.
- Documentos largos (5-10 páginas o más): se crean con `document_save` y se
  completan sección a sección con `document_append`.

## 1. Capacidad

`documents_canvas_v1` la anuncia BeplyChatAI (`Lib/ChatCapabilities.php`) sólo si
BeplyPDFStudio está habilitado y publica `Lib\ChatDocument\DocumentSpec::CONTRACT ===
'beply.document/v1'`; está en `KNOWN_PLUGIN_CAPABILITIES` de node-controller. Node la
activa por instalación (anuncio ∧ cohorte ∧ ¬kill-switch) y el ADK falla cerrado sin
ella. El frontend la ve como `window.beplyChatFeatures.documentsCanvas`: sin PDF
Studio, la opción «Documentos» no aparece.

## 2. Especificación `beply.document/v1`

Bep no escribe HTML. `Lib/ChatDocument/DocumentSpec::validate()` normaliza o
devuelve errores `{path, code, message}`; las propiedades de estilo se ignoran
con un aviso para Bep.

```json
{
  "schema": "beply.document/v1",
  "title": "Propuesta de mantenimiento",            // ≤ 120
  "meta": {
    "subtitle": "…", "date": "2026-10-16",           // AAAA-MM-DD → «16 de octubre de 2026»
    "place": "Valencia", "reference": "P-2026-14", "subject": "…",
    "recipient": {"name": "Ferretería Martínez S.L.", "lines": ["C/ Mayor 12", "46001 Valencia"]},
    "show_company": true,                            // false oculta los datos de la empresa del membrete
    "show_title": true                               // false en cartas con asunto
  },
  "blocks": [                                        // 1..600
    {"type": "heading", "text": "Condiciones", "level": 1},
    {"type": "paragraph", "text": "Texto con **negrita** y *cursiva*.\nSalto de línea.", "align": "justify"},
    {"type": "list", "style": "bullet", "items": ["…"]},
    {"type": "table", "header": ["Concepto", "Importe"], "rows": [["Cuota", "1.200,00 €"]], "caption": "Resumen"},
    {"type": "quote", "text": "…"},
    {"type": "note", "text": "…", "tone": "info|warning|success", "title": "Importante"},
    {"type": "signature", "name": "Ana López", "role": "Dirección", "place_date": "Valencia, a 16 de octubre de 2026"},
    {"type": "spacer", "size": "sm|md|lg"},
    {"type": "page_break"}
  ]
}
```

Límites: ≤ 600 bloques, párrafo ≤ 6000, lista ≤ 100 elementos, tabla ≤ 8 columnas y 200 filas,
documento ≤ 60 000 caracteres de texto (≈ 10 páginas A4: tope de extensión y de coste). Marcado en línea: sólo `**negrita**`, `*cursiva*`
y `_cursiva_` (`Lib/ChatDocument/DocumentInline`).

## 3. Persistencia

Tabla `beply_pdf_chat_documents` (modelo `BeplyPdfChatDocument`): `id, nick (FK users ON
DELETE CASCADE), idempresa, kind (document|audit), title, status (draft|saved),
spec, version, revision, conversation_id, creation_date, last_update`. Sólo el
propietario lo lee o lo cambia (`DocumentService`); un documento ajeno se
comporta como inexistente. Cada escritura sube la revisión (el visor se refresca).
El informe de auditoría nace `saved`.

**Versiones.** `document_save` con `document_id` (reescritura completa: el usuario
pide cambios) crea la versión siguiente y guarda la anterior en
`beply_pdf_chat_document_versions` (`document_id, version, title, spec,
creation_date`, borrado en cascada; máximo 50 por documento). `document_append`
completa la versión actual sin crear otra. `DocumentService::findVersion` aplica
la misma regla de propietario.

## 4. Tools de Bep (`beply.tools/v1`)

`Lib/BeplyToolProvider.php` (de este plugin) publica en `/api/3/beply-tools`,
dominio nativo `documents_canvas` (el catálogo genérico del ADK no las expone) y
permiso de página `ChatAPI`:

| Tool | Modo | Argumentos |
|---|---|---|
| `beplypdfstudio.document_catalog` | read_only | `{}` → formato, ejemplo y marca (`DocumentBranding::describe`) |
| `beplypdfstudio.document_list` | read_only | `{kind?}` |
| `beplypdfstudio.document_get` | read_only | `{document_id}` → resumen + spec |
| `beplypdfstudio.document_save` | autonomous | `{spec, document_id?, kind?, conversation_id?}` + `idempotency_key` |
| `beplypdfstudio.document_append` | autonomous | `{document_id, blocks[], conversation_id?}` → añade al final (documentos largos), valida el conjunto y sube la revisión |

`document_save` responde `{ok:true, document, artifact, warnings}` o
`{ok:false, errors:[{path, code, message}]}` sin escribir.

## 5. Diseño: el estilo de PDF Studio

El PDF lo pinta el motor HTML de PDF Studio
(`BeplyHtmlRenderService::renderChatDocument`, plantilla
`Templates/html/chat-document.html.twig`, WeasyPrint) con la configuración que
devuelve `BeplyPdfRenderService::resolveConfig(null, idempresa)`: el estilo de la
empresa sin formato o el global. Se aplican papel y orientación, márgenes, logo
(posición y tamaño), los cuatro colores (primario en títulos, reglas y cabecera de
tablas; secundario en notas y citas; terciario en filas alternas; texto), la
fuente del manifiesto (`Assets/Fonts/fonts.json`), el texto de pie y la
numeración `{PAGENO} / {nbpg}`. Cabecera de tabla repetida en cada página, firmas
seguidas en una fila (contratos) y saltos de página `page_break`.

El DOCX usa `Lib/ChatDocument/DocumentBranding`, que lee la misma configuración
(colores, fuente, tamaño, papel, márgenes, logo y pie) más el logo y los datos de
la empresa. Un logo sólo vale si sus bytes son PNG, JPEG o GIF (≤ 2 MB).

## 6. Vista previa y exportación (`Controller/PdfStudioChatDocument`)

| Petición | Respuesta |
|---|---|
| `GET ?id=N` / `?id=N&embed=1` | El PDF en línea: la vista previa del panel del chat es el mismo PDF, con sus páginas |
| `GET ?id=N&format=pdf` | El PDF para descargar (`attachment`, nombre saneado) |
| `GET ?id=N&format=docx` | Word (`attachment`) |
| `…&version=V` | Lo mismo con la versión V (404 si no existe); el nombre de archivo lleva ` v<V>` |

Entra quien puede usar el chat (permiso de la página `ChatAPI` si su rol no
tiene ésta); un documento ajeno responde 404 como uno inexistente;
`Cache-Control: no-store`. El visor del chat muestra el PDF sin `sandbox` (Chrome
y Brave no cargan su visor de PDF en un iframe con sandbox) y, al subir la
revisión, recarga el iframe.

- **DOCX**: escritor propio con ZipArchive (estilos Title/Heading1-3/Quote con la
  fuente, el tamaño y los colores del estilo; listas con `numbering.xml`, tablas
  con cabecera en el color primario, logo en el membrete y pie con número de
  página).

## 7. Artefacto (`beply.artifact/v1`, `kind:"document"`)

```json
{"contract": "beply.artifact/v1", "kind": "document", "id": "document:12",
 "title": "Propuesta de mantenimiento", "revision": 3, "status": "draft",
 "version": 2, "variant": "document", "url": "PdfStudioChatDocument?id=12&embed=1", "open": true}
```

`version` (1-10 000) fija la versión de ESA tarjeta: el chat añade `&version=N` a la
vista previa y a las descargas, así que una tarjeta antigua abre su versión. El
visor muestra un selector «Versión N» cuando la conversación conoce más de una.

`variant` es `document` o `audit`; `url` sólo puede ser
`PdfStudioChatDocument?id=<n>[&embed=1]` con el mismo id (ADK, Node y navegador lo
reconstruyen). El informe de auditoría va siempre con `open:false`.
`utils/artifacts.sanitizeArtifact` añade `embedUrl`, `openUrl`, `pdfUrl` y
`docxUrl` construidas desde el id; `ArtifactViewer` muestra «PDF» y «DOCX» en la
cabecera.

## 8. Flujo del turno

Navegador `documentMode: true` → `/chat-api/stream` (sólo con la capacidad,
nunca con auditoría ni modo informe) → Node `documentMode` (huella del
`requestKey` sólo si está activo) → ADK `document_mode` → estado
`_document_mode` e instrucción `[MODO DOCUMENTOS]`. El orquestador tiene las
tools siempre; los agentes de dominio sólo en modo Documentos (para seguir
editando tras consultar el ERP); el analista sólo el guardado del informe de
auditoría. Si el analista no lo guarda, el servidor lo compone al final de la
auditoría con su respuesta y la cobertura de consultas (ADK
`tools/document_canvas.save_audit_report`).
