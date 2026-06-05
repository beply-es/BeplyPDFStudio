#!/usr/bin/env bash
#
# Prepara el motor HTML→PDF de BeplyPDFStudio: WeasyPrint en un entorno virtual propio del
# plugin (.venv). Idempotente. Pensado para ejecutarse en el build de la imagen o en deploy.
#
#   - Twig (HTML) lo aporta el core de FacturaScripts.
#   - WeasyPrint (Python) convierte ese HTML/CSS a PDF respetando el estándar de paginación CSS
#     (@page, position:fixed, running headers/footers, numeración).
#
# Requiere apt (Debian/Ubuntu). El .venv NO se versiona (.gitignore); se construye aquí.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VENV="${PLUGIN_DIR}/.venv"

echo "== BeplyPDFStudio: setup WeasyPrint en ${VENV} =="

# 1) Dependencias del sistema (Python + Pango/cairo/gdk-pixbuf/ffi para WeasyPrint).
if command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq --no-install-recommends \
        python3 python3-venv python3-pip \
        libpango-1.0-0 libpangocairo-1.0-0 libpangoft2-1.0-0 \
        libcairo2 libgdk-pixbuf-2.0-0 libffi8 libharfbuzz0b fonts-dejavu-core
else
    echo "WARNING: apt-get no disponible; instala manualmente python3 + libpango/cairo/gdk-pixbuf."
fi

# 2) Entorno virtual + WeasyPrint.
if [ ! -x "${VENV}/bin/python" ]; then
    python3 -m venv "${VENV}"
fi
"${VENV}/bin/pip" install --quiet --upgrade pip
"${VENV}/bin/pip" install --quiet weasyprint

# 3) Verificación.
"${VENV}/bin/python" -c "import weasyprint; print('WeasyPrint', weasyprint.__version__, 'OK')"
echo "== setup-weasyprint.sh terminado =="
