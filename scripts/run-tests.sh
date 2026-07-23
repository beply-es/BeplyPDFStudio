#!/usr/bin/env bash
set -euo pipefail

CONTAINER="${BEPDF_CONTAINER:-beplypdfstudio-fs}"
PLUGIN="/var/www/html/Plugins/BeplyPDFStudio"

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
  echo "Container not running: $CONTAINER" >&2
  exit 1
fi

echo "== PHP lint =="
docker exec "$CONTAINER" sh -c "find '$PLUGIN' -name '*.php' -not -path '*/.git/*' -not -path '*/.venv/*' -not -path '*/vendor/*' -print0 | xargs -0 -n1 php -l >/tmp/beplypdfstudio-lint.log && tail -5 /tmp/beplypdfstudio-lint.log"

echo "== Unit tests =="
docker exec "$CONTAINER" php "$PLUGIN/Tests/run-unit.php"

echo "== E2E tests =="
docker exec -u www-data "$CONTAINER" php "$PLUGIN/Tests/run-e2e.php"

echo "== Template tests (HTML/WeasyPrint personalización) =="
docker exec -u www-data "$CONTAINER" php "$PLUGIN/Tests/run-template.php"

echo "== Format/template matrix (FormatoDocumento aplicado a cada plantilla) =="
docker exec -u www-data "$CONTAINER" php "$PLUGIN/Tests/run-format-template.php"

echo "== A5 responsive (escala por papel: muestra corta = 1 página) =="
docker exec -u www-data "$CONTAINER" php "$PLUGIN/Tests/run-a5.php"

echo "== Performance (render PDF real < 1s y sin página extra) =="
docker exec -u www-data "$CONTAINER" php "$PLUGIN/Tests/run-performance.php"

echo "== Document layout smoke (documentos reales sin cortes ni overflow) =="
docker exec -u www-data "$CONTAINER" php "$PLUGIN/Tests/run-document-layout.php"

echo "== Bottom anchor (totales/recibos medidos contra el fondo útil) =="
docker exec -u www-data "$CONTAINER" php "$PLUGIN/Tests/run-bottom-anchor.php"

echo "== Fiscal QR blocks (TicketBAI/VERIFACTU, all templates, long invoices) =="
docker exec -u www-data "$CONTAINER" php "$PLUGIN/Tests/run-fiscal-qr.php"

echo "== Generic core prints (misma plantilla para listados/fichas: is_document) =="
docker exec -u www-data "$CONTAINER" php "$PLUGIN/Tests/run-generic.php"

echo "OK"
