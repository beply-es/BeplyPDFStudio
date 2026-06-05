# Resumen ejecutivo

## Plugin de referencia

Nombre: PlantillasPDF
Proveedor: tercero (FacturaScripts marketplace)
URL pública: pendiente de registrar
Licencia: COMERCIAL / PRIVATIVA (pendiente de confirmar EULA exacto)
Versión observada: 6.32 (según artefacto aportado por el cliente)
Fecha de revisión: 2026-05-29

## Plugin propio

Nombre interno: BeplyPDFStudio
Repositorio: github.com/tonomolla6/BeplyPDFStudio
Responsable: equipo de plataforma Beply
Fecha de inicio: 2026-05-29

## Objetivo

Desarrollar una **reimplementación clean-room con paridad funcional completa** respecto a
las funcionalidades observables, documentadas o verificables del plugin de referencia,
sin copiar código, assets, plantillas, estructura interna, nombres internos ni diseño
exacto.

## Principios aplicados

1. No acceso al código original.
2. No copia de recursos protegidos.
3. Exploración obligatoria por navegador (Playwright).
4. Extracción legal exhaustiva (360º).
5. Inventario funcional completo.
6. Implementación propia.
7. Testing obligatorio.
8. Compatibilidad razonable con plugins relacionados.
9. Revisión de no copia.
10. Aprobación interna antes de release.

## Resultado esperado

Un plugin propio de Beply (BeplyPDFStudio) con código, arquitectura, modelo de datos, UI,
plantillas, assets, tests y documentación propios.

## Nota de desviación de entorno

Por decisión del responsable, la observación de la referencia se realiza sobre el **mismo
entorno** de desarrollo (`:8013`), no en un entorno separado como recomienda el §8 del
manual. Mitigación aplicada: la instalación la realiza FacturaScripts (no se abre el ZIP
manualmente), la observación es **solo por navegador** y **no se lee ningún archivo** del
plugin de referencia. Ver [03_registro_accesos.md](03_registro_accesos.md). El riesgo
legal **no se declara como cero**.
