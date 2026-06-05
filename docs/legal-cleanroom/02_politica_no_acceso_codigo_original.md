# Política de no acceso al código original

## Regla general

Ninguna persona ni herramienta de IA encargada de implementar BeplyPDFStudio podrá
acceder al código fuente, estructura interna, plantillas, assets, migraciones o base de
datos interna del plugin de referencia (PlantillasPDF).

## Permitido

- Uso del plugin por navegador.
- Capturas propias.
- Evidencias generadas (PDF, exports) por uso normal.
- Consulta de documentación pública y changelogs públicos.
- Especificación funcional limpia.
- Desarrollo propio desde cero.

## Prohibido

- Leer archivos internos (PHP, Twig, CSS, JS, XML, YAML, JSON) del plugin de referencia.
- Copiar código, plantillas, CSS/JS, assets, modelo de datos o nombres internos.
- Consultar su base de datos / usar dumps.
- Ingeniería inversa.
- Usar IA para analizar su código.

## Medidas técnicas

- Repo Beply limpio (BeplyPDFStudio), sin archivos del plugin de referencia.
- Claude Code trabaja en el repo Beply.
- Playwright se usa **solo como navegador**.
- Registro de accesos obligatorio (03).
- **Desviación §8 (mismo entorno):** PlantillasPDF se instala en el FS de `:8013`. La
  instalación la ejecuta FacturaScripts (no se abre el ZIP a mano). Pese a que sus
  archivos quedan en disco, se tratan como **zona prohibida**: no se hace `cat`, `grep`,
  `find`, `ls`, ni se abren con ningún editor.

## Acceso accidental

Si ocurre acceso accidental al código de la referencia:

1. Detener trabajo.
2. Registrar incidente (03 / §54).
3. No usar la información vista.
4. Avisar al responsable.
5. Rehacer la parte afectada desde especificación limpia.
