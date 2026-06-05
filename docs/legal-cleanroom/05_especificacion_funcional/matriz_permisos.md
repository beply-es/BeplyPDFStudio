# Matriz de permisos y roles

Solo se ha observado con el rol administrador. El control de acceso de Beply se apoyará en
el sistema de permisos nativo de FacturaScripts (acceso por página/controlador).

| ID permiso | Rol/Usuario | Pantalla/Acción | Permitido observado | Denegado observado | Evidencia | Implementación Beply | Test | Estado |
|------------|-------------|-----------------|----------------------|--------------------|-----------|----------------------|------|--------|
| PERM-001 | Admin | Acceder a Plantillas PDF | Sí | — | ref_01 | Página propia con control nativo FS | E2E | Pendiente |
| PERM-002 | Admin | Editar configuración | Sí | — | ref_01 | Control nativo FS | E2E | Pendiente |
| PERM-003 | Admin | Personalizar diseño por formato | Sí | — | ref_02 | Pantalla Beply sobre `FormatoDocumento` como scope | E2E | Pendiente |
| PERM-004 | Usuario sin permiso | Acceder a Plantillas PDF | — | (no probado) | — | Denegar vía permisos FS | E2E | Pendiente |

## Limitación
- No se probaron roles limitados (no había usuarios no-admin en el entorno). Documentado.
