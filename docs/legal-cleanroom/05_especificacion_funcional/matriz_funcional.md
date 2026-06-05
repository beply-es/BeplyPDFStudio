# Matriz funcional (resumen por áreas)

Vista de alto nivel. El detalle por funcionalidad está en
[inventario_funcional_completo.md](inventario_funcional_completo.md) (38 FUNC) y por campo
en [matriz_campos.md](matriz_campos.md) (41 campos).

| Área | Funcs | Necesidad funcional | Decisión Beply | Estado |
|------|-------|---------------------|----------------|--------|
| Acceso/UI | FUNC-001 | Punto de entrada propio en el menú | Controlador propio Beply | Pendiente |
| Diseños | FUNC-002 | Elegir entre varios diseños base | Galería + 5 diseños propios | Pendiente |
| Página | FUNC-003,004,008 | Papel, orientación, márgenes | Config propia | Pendiente |
| Marca | FUNC-005..007,011 | Logo (pos/tamaño) y colores | Config propia | Pendiente |
| Tipografía | FUNC-009,010 | Fuente y tamaños | Config propia | Pendiente |
| Datos visibles | FUNC-012..020 | Mostrar/ocultar datos del documento | Config propia (conmutadores) | Pendiente |
| Líneas | FUNC-021..024 | Columnas, alineación, tipo, altura | Selector/config propios | Pendiente |
| Textos | FUNC-025..027 | Texto final, agradecimiento, pie | Config propia (tokens propios) | Pendiente |
| Avanzado | FUNC-028,029 | Contraseña PDF, imagen producto | Config propia | Pendiente |
| Acciones | FUNC-030..032 | Previsualizar, guardar, deshacer | Implementación propia | Pendiente |
| Formatos | FUNC-033..037 | Asignar por tipo/empresa/serie | Integrar con FormatoDocumento nativo | Pendiente |
| Cobertura | FUNC-038 | Facturas/albaranes/pedidos/presupuestos | Export propio sobre core | Pendiente |
