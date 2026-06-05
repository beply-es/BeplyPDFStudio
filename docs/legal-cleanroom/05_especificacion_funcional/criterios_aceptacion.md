# Criterios de aceptación

Formato: Dado [contexto], cuando [acción], entonces [resultado].

| ID funcional | Criterio de aceptación | Tipo de prueba | Estado |
|-------------|-------------------------|----------------|--------|
| FUNC-002 | Dada la galería, cuando elijo un diseño, entonces la configuración carga sus valores por defecto | E2E | Pendiente |
| FUNC-003/004/008 | Dado papel/orientación/márgenes válidos, cuando guardo, entonces persisten; si son inválidos, se rechazan | UT+E2E | Pendiente |
| FUNC-011 | Dado un color no HEX, cuando guardo, entonces se rechaza con mensaje | UT | Pendiente |
| FUNC-021 | Dadas columnas seleccionadas, cuando guardo, entonces el PDF muestra solo esas columnas en ese orden | UT+E2E | Pendiente |
| FUNC-025/027 | Dado un texto final/pie, cuando genero el PDF, entonces aparece con su alineación; el pie resuelve {PAGENO}/{nbpg} | E2E | Pendiente |
| FUNC-030 | Dada una configuración, cuando previsualizo, entonces obtengo un PDF de muestra | E2E | Pendiente |
| FUNC-033..036 | Dado un formato por tipo/empresa/serie, cuando genero el PDF de un documento de ese ámbito, entonces se aplica ese formato | E2E | Pendiente |
| FUNC-038 | Dado un documento (factura/albarán/pedido/presupuesto), cuando exporto a PDF, entonces se genera con el diseño configurado | E2E | Pendiente |
| Fallback | Dado un documento sin formato asignado, cuando exporto, entonces se usa el diseño por defecto | UT+E2E | Pendiente |
