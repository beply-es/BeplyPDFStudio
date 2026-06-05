# Revisión de licencia del plugin de referencia

## Identificación

Plugin: PlantillasPDF
Proveedor: tercero (FacturaScripts marketplace)
URL pública: pendiente de registrar
Fecha de revisión: 2026-05-29

## Licencia detectada

Tipo: COMERCIAL / PRIVATIVA (pendiente de confirmar términos exactos del EULA)

## Condiciones relevantes

- Software de pago; debe asumirse código, plantillas y assets protegidos por copyright.
- Posibles cláusulas de prohibición de ingeniería inversa (pendiente de verificar).
- Uso sujeto a licencia del titular.

## Riesgos detectados

| Riesgo | Nivel | Mitigación |
|--------|-------|------------|
| Copia de código | Alto | No acceso al código original |
| Copia de assets | Alto | Assets propios |
| Copia de diseño exacto | Medio/Alto | Diseño visual propio |
| Copia de estructura interna | Alto | Modelo propio |
| Uso de nombres similares | Medio | Naming Beply (BeplyPDFStudio, beply_pdf_*) |
| Referencia en mismo entorno | Medio | Solo navegador, sin leer código (ver 03) |

## Decisión

No se copiará ni reutilizará código, estructura interna, plantillas, assets, textos
largos, diseño exacto ni nombres internos del plugin de referencia. El desarrollo se hará
mediante clean-room, con especificación funcional limpia, testing y trazabilidad.

## Pendientes

- [ ] Revisión legal externa si se comercializa ampliamente.
- [ ] Confirmar términos exactos del EULA de PlantillasPDF.
- [ ] Confirmar licencia válida de uso para la observación.
- [ ] Revisar marca/nombre comercial.
