# Casos límite

- [ ] Color inválido (no HEX) → rechazo.
- [ ] Margen negativo / fuera de rango → rechazo.
- [ ] Tamaño de fuente fuera de rango → rechazo.
- [ ] Fuente no soportada → rechazo o fallback.
- [ ] Papel no soportado → rechazo.
- [ ] Columna de línea desconocida → rechazo con lista válida.
- [ ] Sin columna mínima (descripción) → rechazo.
- [ ] Desajuste columnas/alineación/tipos → autocompletar o rechazar.
- [ ] Texto final/pie demasiado largo → truncar/paginar.
- [ ] Logo formato/peso inválido → rechazo.
- [ ] Documento sin líneas → PDF con tabla vacía, sin error.
- [ ] Documento con muchas líneas / multipágina → paginación + cabecera repetida + pie con {PAGENO}/{nbpg}.
- [ ] Cliente sin NIF / empresa sin logo → no romper layout.
- [ ] Formato eliminado en uso → fallback a diseño por defecto.
- [ ] Sin formato asignado → diseño por defecto.
- [ ] Multiempresa: formatos aislados por empresa.
- [ ] Factura rectificativa / proforma (serie específica) → formato correcto por serie.
- [ ] Contraseña de PDF aplicada → PDF protegido.
