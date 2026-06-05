# Registro de prompts de IA

## Sesión 2026-05-29
- **Contexto:** el usuario aportó el `MANUAL_FINAL_CLONACION_LEGAL.md` (metodología
  clean-room de Beply) como guía y prompt maestro (§57).
- **Encargo:** reimplementación clean-room con paridad funcional de PlantillasPDF, sin
  copiar código; instalar y observar la referencia por navegador en el mismo entorno
  (desviación §8 aceptada por el responsable), montar el expediente legal, extraer
  funcionalidades, diseñar e implementar BeplyPDFStudio, con tests.
- **Limitaciones declaradas:** no leer código/estructura/BD de la referencia; observación
  solo por navegador; no copiar nombres internos, plantillas ni assets.
- **URLs usadas (navegador, sin credenciales de la referencia):** `http://localhost:8013`
  (entorno propio con la referencia instalada para observación).
- **Resultado resumido:** expediente 00–10 + matrices de extracción + diseño propio +
  implementación + 25 UT + integración + E2E. Pendiente: profundizar preview/PDF real,
  revisión legal externa.
