#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEFAULT_TEST_PASS_FILE="$ROOT/../../../passfs-beplytests.txt"
DEFAULT_ADMIN_PASS_FILE="$ROOT/../../../passfs.txt"
PASS_FILE="${BEPLY_FS_PASS_FILE:-"$DEFAULT_TEST_PASS_FILE"}"
if [[ -z "${BEPLY_FS_PASS_FILE:-}" && ! -f "$PASS_FILE" ]]; then
  PASS_FILE="$DEFAULT_ADMIN_PASS_FILE"
fi

export BEPLY_FS_URL="${BEPLY_FS_URL:-http://46.224.63.98:8013}"
if [[ -z "${BEPLY_FS_USER:-}" && -f "$PASS_FILE" ]]; then
  export BEPLY_FS_USER="$(sed -n '1p' "$PASS_FILE")"
fi
if [[ -z "${BEPLY_FS_PASSWORD:-}" && -f "$PASS_FILE" ]]; then
  export BEPLY_FS_PASSWORD="$(sed -n '2p' "$PASS_FILE")"
fi

export BEPLY_PDF_EVIDENCE_DIR="${BEPLY_PDF_EVIDENCE_DIR:-"$ROOT/docs/testing/evidencias/playwright-visual-compat"}"

PW_TMP="${BEPLY_PLAYWRIGHT_TMP:-/tmp/beplypdfstudio-playwright}"
if [[ ! -x "$PW_TMP/node_modules/.bin/playwright" ]]; then
  mkdir -p "$PW_TMP"
  npm --prefix "$PW_TMP" install --silent --no-audit --no-fund @playwright/test@1.60.0
fi

export NODE_PATH="$PW_TMP/node_modules${NODE_PATH:+:$NODE_PATH}"

cd "$ROOT"
"$PW_TMP/node_modules/.bin/playwright" test Tests/playwright/visual-compat.spec.js --workers=1 --reporter=line
