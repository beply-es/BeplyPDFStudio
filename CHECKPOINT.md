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

## Revisión adversarial y candidato final
- Guarda de permisos revalidada bajo bloqueo de factura/recibos, incluida propiedad de cada recibo según Core. HTTP GET rechazado sin cambios; POST sin CSRF rechazado; POST válido probado.
- Tests instalados: copia limpia, usuario sin permisos sin efectos, transición a emitida rechaza garantía inconsistente, emitida válida inmutable, servicio rechaza documento bloqueado.
- PDF real en los nueve diseños HTML: todas las filas y 500/11600 presentes. Sin degradación silenciosa ante error de configuración/render.
- Migración aislada 2.7 -> candidato 4.2 -> rollback 2.7: campos previos de estilo preservados, fechas comparadas por instante (Core cambia representación al releer). Datos sintéticos únicamente.
- DEV fixture provisioning solicitado UNA vez: run 36404839146, SHA beply-k3s 37e0db3a63e362804bab757a1940f43199ad287d, retain_for_followup=true. Al terminar recuperar identidad exacta y limpiar sólo ese servicio por workflow canónico.
- PR27 draft. CI inicial 996c4045c49e695175926f87f57cf089a0654c03 verde runs 36404653404/36404720430; nuevos guardas requieren CI sucesora.
- Progreso 40%; local verde, CI candidato final/DEV/PROD pendientes.

## Release DEV publicada; gates activos
- PR27 merged: main 6c486f834304599bcd1a48cdd7c013d630c88d90. Tests main 36405941499 SUCCESS, release DEV 36405941564 SUCCESS. Candidato final rama 678ba318ed1df0b11d2b2a29ad22ed7c3e6dfd1c tests 36405797088/36405804407 SUCCESS.
- ZIP DEV descargado y comprobado: BeplyPDFStudio-dev-4.2.zip, 5357750 bytes, sha256 022a110db90659897512661b98080cf796d22c8ea872840aeb99d43aa154399f; ini4.2 y código presente. DEV pluginId7129e3a1-8a43-4ff0-a56d-5e5ab0979802, versionId5f595b96-e444-4fb7-83df-cae7a01151b5, pending_review. Aprobar sólo al UUID de fixture propia cuando exista; no latest/global.
- Corrección de lectura PROD: primeras selects sin connect devolvían [] y NO demostraban ausencia. Con conexión explícita y count se confirma 1 estilo (id1, empresa1, azure, activo), 3 formatos (1/2 FacturaCliente,3 PresupuestoCliente). Hash estilos d8c80df9ff83b121f5c9cb7187132a6b1fa42a129d66f93953130191f482d5bf; formatos 71de95482a4b3a41fb5f9c3849c8f7cd04ad4b88b9b1ca7bd09bcad52f0702aa.
- PROD backend/runtime coinciden2.7 synced. pluginId7b189979-b194-48fd-8105-4627b42ab988; versionId31306a10-865d-4829-9f3a-5821aadd2734; checksum sha256:51bd2ee355709b4b6ac595ff533c059379dc8e2087ad73f36b9450b67c2fc1fb. Revert canónico sólo al mismo servicio, versión2.7; pre-backup obligatorio en rollout.
- Kit PROD sin escrituras de factura probado local: verify-installed.php construye documento ficticio sólo en memoria, renderiza Azure y comprueba texto del PDF, borra temporal. La activación prevista sólo cambia show_certification_settlement en estilo1, con CAS y lectura posterior; no cambia documentos existentes.
- DEV provisioning run36404839146 sigue queued (capacidad ARC24/24 observada). Un único proceso foreground wait-dev.py espera ese run/SHA; no duplicar dispatch ni monitor. Sesión exec59632.
- Camino PROD actual: tagv4.2 publica release GitHub pero NO ingesta catálogo. Después workflow prod-plugin-artifact-ingest.yml con pins del ZIP, approve scope servicio exacto, rollout cohorte explícita, readback y configuración. Tag aún NO creado: depende de DEV100.
- Progreso45%; CI verde; DEV0/PROD0. No cierre.
- DEV catálogo releído en runtime: versionId5f595b96-e444-4fb7-83df-cae7a01151b5 checksum/size/sourceReleaseTag coinciden con ZIP descargado. No aprobación todavía.
- Prueba migración ampliada ejecuta Init::update() completo de2.7 y4.2 además de redeploy/modelos, y vuelta2.7: configuración previa preservada. Kit de setting apply/rollback con CAS probado sobre DB sintética; compara todos los campos salvo el booleano autorizado y modificado.
- Estado inicial de facturas del servicio PROD confirmado: Boceto10 editable y predeterminado; Emitida11 no editable. Compatible con configurar garantía antes de emitir.
- DEV fixture run36404839146 pasó a in_progress; mantener única espera, siguiente efecto sólo tras éxito e identidad retenida.
- DEV fixture retenida SUCCESS: servicio8f4c5a8c-d022-4914-9dd0-7713bd805923, host rolloutcohortsmokea-09281004wedh.services.devbeply.es. Own follow-up, cleanup exacto obligatorio.
- Aprobar DEV dispatch único run36407941821 SHA53f5367cb22e3e07457367f2fa51bd9e2cb502fe, scope service fixture, approved_closed/no latest/no stable. Delta respecto workflow leído sólo helper remove plugin no-op (no efecto en aprobación).
- DEV actor extra rechazado por guarda canónica user-creation-blocked; readback demostró que no se creó. Se reutiliza administrador provisionado del tenant sintético, sin leer/copiar credenciales ni cambiar usuarios. Fixture LAB preparada: serie y forma pendiente, cero facturas creadas aún. Prueba instalada usa todos los Init activos; no plugins fiscales externos activos. Pruebas de permisos con usuario nuevo permanecen locales por esta guarda; resto del contrato instalado se ejecutará en DEV.
- Rojo operador aprobaciónDEV run36407941821: normalize_sha256_checksum exige prefijo sha256:, se mandó hex sin prefijo. Rechazo previo a aprobación/instalación, no fallo de producto. Sucesor corregido run36409594837 SHA98222fbd4b1a5f7896db445ce7f9b51a66ad24e2, misma identidad/version/scope, checksum COMPLETO sha256:022a110db90659897512661b98080cf796d22c8ea872840aeb99d43aa154399f. Scripts/workflow de aprobación sin cambios en delta53f..982; espera única sesión exec68133.
- Rollback ZIP PROD2.7 descargado y hash independiente coincide con catálogo actual: 51bd2ee355709b4b6ac595ff533c059379dc8e2087ad73f36b9450b67c2fc1fb.
- HTTP local ampliado: guardar anterior21000 por POST válido, readback SQL confirma21000; segundo POST restaura20000 y GET relee20000 sin alertas. Redirect del Core devuelve respuesta intermedia; no confundir ausencia de formulario en esa respuesta con fallo de guardado.
- Sucesor approveDEV36409594837 SUCCESS: aprobación atómica closed, scope único8f4c5a8c-d022-4914-9dd0-7713bd805923/stable; isLatest=false,isStable=false; readback exacto guardado en dev-approval.json.
- RolloutDEV dispatch único run36410463138 SHA98222fbd4b1a5f7896db445ce7f9b51a66ad24e2, service explícito fixture, beplypdfstudio@4.2, pre_backup_required=true, force_update=false, activeSLA900, timeout1200, observación postresume60s. Espera única exec51035. Aún no prueba funcional del candidato instalado.
- Drift de configuración PROD detectado en lectura posterior: estilo1 ahora diseno=legacy_standard (antes azure), show_without_vat=false, hide_receipts=false, hide_payment_methods=false. No hubo escrituras nuestras en PROD. Preservar selección ACTUAL; no restaurar ni imponer Azure. Kit configura únicamente booleano nuevo con CAS de preimagen fresca y verifica resolveConfig del formato instalado, sin forzar diseño.

## DEV_RED funcional: campos de modelo cacheados
- RolloutDEV36410463138 SUCCESS + hashes de código exactos4.2. Backup previo both completo id067e7d0f-0d53-40db-9cc8-a38d8f7f68a0.
- Prueba instalada con todos los plugins: primer reparto11600/500 correcto; segundo guardado rechaza reparto porque metadata no persistió. Fixtures factura/cliente limpiadas por finally en dos reproducciones.
- Evidencia causal: DB y Dinamic XML contienen las6columnas bpf_; Cache model-fields-FacturaCliente y getModelFields() no contienen ninguna. Core salva sólo campos de esa caché: apunta a invalidación de metadatos tras actualización. No confundir get_object_vars vacío (Core guarda atributos privados) con ausencia de extensión; reflexión confirma extensiones registradas.
- Corregir Init de migración para invalidar metadatos de FacturaCliente, más guardas de esquema y readback transaccional antes de confirmar reparto. Reproducir caché antigua en lab y añadir regresión instalada explícita de persistencia de metadata.
- Nueva rama limpia desde main6c486f834304599bcd1a48cdd7c013d630c88d90: fix/certification-schema-cache-20260928.4.2 es inmutable y cerrada sólo al DEV propio; sucesor requiere4.3, CI y nueva adopciónDEV antes de PROD. PROD0; no emitir facturas reales. Goal original sigue activo.
- TDD causal local: inyectar metadatos antiguos en Cache y propiedad estática -> Init::update de4.2 NO los refresca (RED).4.3 invalida ambas capas tras migrar FacturaCliente -> GREEN. Se exige esquema completo antes de repartir y se releen datos persistidos antes del commit.
- Regresión instalada ampliada GREEN: esquema obsoleto no toca recibo original; términos persistidos explícitos; hook competidor corrompe metadata después de los UPDATE de recibos -> lectura detecta discrepancia y rollback restaura recibos y términos. También persistencia/idempotencia/copias/emitidas/CSRF previas siguen verdes.142unit y scanPHP8.4 pasan.
- Cierre de transacción comprueba commit; mensaje ante error no promete ausencia de cambios si resultado incierto. Review propio: causa probada y corrección acotada; renderer y cálculo sin cambios, evidencia PDF9diseños sigue aplicable por source.
