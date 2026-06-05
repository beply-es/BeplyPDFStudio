# Registro de accesos

| Fecha | Persona/Herramienta | Entorno | Tipo de acceso | Código original accesible | Resultado | Observaciones |
|------|----------------------|---------|----------------|----------------------------|-----------|---------------|
| 2026-05-29 | Claude Code | Repo Beply (BeplyPDFStudio) | Desarrollo clean-room | No | Inicio | Trabaja solo en el repo Beply. No accede al código de la referencia. |
| 2026-05-29 | Claude Code / FacturaScripts | FS :8013 (mismo entorno) | Instalación de la referencia vía uploader de FS | Sí (queda en disco) | Desviación §8 registrada | PlantillasPDF instalado en el MISMO entorno por decisión del responsable. FS descomprime el ZIP; **no se abre/lee a mano**. Se trata como zona prohibida. |
| 2026-05-29 | Playwright (navegador) | FS :8013 | Navegación UI de la referencia | No (solo navegador) | Exploración/capturas | Observación funcional por navegador. Sin filesystem, sin código. |

## Notas

- **Desviación §8:** la referencia y el desarrollo comparten entorno (`:8013`). Se mitiga
  con observación solo-navegador y prohibición de leer sus archivos. Para un expediente de
  release sólido se recomienda repetir la observación en un entorno separado.
- Toda sesión futura (humana o IA) debe añadir su fila antes de trabajar.
- Si alguien lee accidentalmente código de PlantillasPDF, abrir incidente (§54).
