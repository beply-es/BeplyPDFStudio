# Certificaciones y garantías — 2026-09-28
- taskId: pdfstudio-certificaciones-20260928; owner thread 01a0e736-5bb7-7383-9817-646afaea53f3.
- Goal: local/TDD -> CI exacto -> DEV convergencia/funcional -> PROD servicio autorizado -> readback/cleanup/rollback.
- Repo: beply-es/BeplyPDFStudio; baseline main c39588450a547fab11688ee0a4eb5ce4fcce8be3; rama feat/certificaciones-garantias-20260928; worktree exclusivo.
- Target confirmado por ingress PROD: instalaciones-y-reparacio.beply.es; servicio d6d75d61-3c43-4ef2-8622-a641e0516d36. Runtime PDFStudio 2.7; nueva baseline 4.1 requiere comprobar migración/preservación de formatos.
- Autoridad: implementar y activar función para ese servicio; no emitir facturas reales, registrar cobros ni cambiar documentos existentes. Disponibilidad nunca global.
- Contrato: desglose de acumulado/anterior/periodo coherente con neto real. Las líneas comerciales representan el periodo (o incluyen ya su deducción); el resumen reconstruye acumulado = anterior + neto, sin segunda deducción. Garantía separada del total fiscal, con base y porcentaje explícitos, control de recibos pendiente y PDF líquido.
- Configuración por formato, off por defecto. Datos por factura; no cambios en documentos emitidos. Reutilizar extensiones PDF y modelos/recibos core.
- Gates: ownership/target verde; local/TDD pendiente; CI/DEV/PROD pendientes. Progreso 5%, NO CIERRE.
- Rollback: revert del cambio local; despliegue canónico versión previa y restauración sólo configuración propia con preimagen. Snapshot y ensayo antes de PROD; columnas aditivas se conservan. Ningún rollback de datos ajenos.
- CARRIL RAPIDO: no (rollback live aún no probado).

## Local 2026-09-28
- TDD rojo clase/plan ausentes -> cálculo en céntimos y reparto idempotente verdes.
- 142 unit tests baseline verdes; nuevo runner aritmética/recibos verde; scan PHP8.4 verde.
- Runtime aislado `certificaciones-fs`/`certificaciones-pg`, red propia; Core copiado SOLO código del servicio PROD autorizado (sin config/MyFiles/datos). DB nueva sintética. Puerto local 18828.
- Prueba instalada crea factura sintética 10000+2100, guarda términos, confirma recibos 11600/500, idempotencia, CAS obsoleto rechazado, entrada inválida rollback, PDF bloqueado si recibo ajeno y cleanup modelo. PDF real renderizado y leído visualmente. HTTP POST sin CSRF rechazado y readback sin cambio; POST válido probado.
- Detectado Core del lab antiguo distinto del cliente; retirado ese baseline, no cambios ajenos para acomodarlo. Detectado tipo int/string al comparar preimagen -> canonicalización estable, misma identidad sin casts de importes distintos.
- Estado 30%: local funcional preliminar; pendiente revisión adversarial, CI exacta, DEV100 y PROD100. Nunca publicar alcance global. No se ha escrito en PROD.
- Evidencia sintética local /tmp/beply-certificaciones-20260928; fixtures previos de exploración quedan en DB propia a destruir al cierre. Helpers HTTP solo loopback.
