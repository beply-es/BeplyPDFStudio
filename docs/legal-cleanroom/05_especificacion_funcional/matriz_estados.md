# Matriz de estados observables

| ID estado | Entidad/Proceso | Estado visible | Cómo se alcanza | Acciones disponibles | Resultado esperado | Evidencia | Implementación Beply | Test |
|-----------|-----------------|----------------|-----------------|----------------------|-------------------|-----------|----------------------|------|
| STATE-001 | Plugin | Activo / Inactivo | Activar/Desactivar en AdminPlugins | — | Funcionalidad disponible o no | ref_00 | Estado nativo FS | — |
| STATE-002 | Configuración global | Guardada / con cambios sin guardar | Editar campos / Guardar / Deshacer | Guardar, Deshacer, Previsualizar | Persistencia o descarte | ref_01 | Estado propio | E2E |
| STATE-003 | FormatoDocumento | Existe / asignado por ámbito | Alta en pestaña Formatos | Editar, borrar | Aplica a documentos del ámbito | ref_02 | FormatoDocumento del core | E2E |

Nota: no se observaron máquinas de estado complejas; el modelo es configuración + formatos.
