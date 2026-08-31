#!/usr/bin/env bash
set -euo pipefail

required=(
  BEPLY_API_URL
  BEPLY_CI_TOKEN
  PLUGIN_ZIP
  PLUGIN_FS_NAME
  PLUGIN_SLUG
  PLUGIN_VERSION
  SOURCE_REPO_FULL_NAME
  SOURCE_BRANCH
  RELEASE_TRACK
  SOURCE_RELEASE_TAG
  SOURCE_RELEASE_URL
  SOURCE_PUBLISHED_AT
)
for name in "${required[@]}"; do
  if [ -z "${!name:-}" ]; then
    echo "::error::${name} is required for exact catalog upload" >&2
    exit 1
  fi
done

if [ ! -f "$PLUGIN_ZIP" ]; then
  echo "::error::Plugin ZIP does not exist: $PLUGIN_ZIP" >&2
  exit 1
fi

actual_checksum="sha256:$(sha256sum "$PLUGIN_ZIP" | cut -d' ' -f1)"
actual_size="$(stat -c %s "$PLUGIN_ZIP")"
expected_checksum="${EXPECTED_CHECKSUM:-$actual_checksum}"
expected_size="${EXPECTED_FILE_SIZE:-$actual_size}"

if [ "$actual_checksum" != "$expected_checksum" ]; then
  echo "::error::Raw ZIP checksum $actual_checksum does not match pinned checksum $expected_checksum" >&2
  exit 1
fi
if [ "$actual_size" != "$expected_size" ]; then
  echo "::error::Raw ZIP size $actual_size does not match pinned size $expected_size" >&2
  exit 1
fi

query_witness() {
  curl -sS -G -w $'\n%{http_code}' \
    -H "Authorization: Bearer ${BEPLY_CI_TOKEN}" \
    --data-urlencode "sourceRepoFullName=${SOURCE_REPO_FULL_NAME}" \
    --data-urlencode "sourceReleaseTag=${SOURCE_RELEASE_TAG}" \
    --data-urlencode "pluginSlug=${PLUGIN_SLUG}" \
    --data-urlencode "version=${PLUGIN_VERSION}" \
    --data-urlencode "sourceBranch=${SOURCE_BRANCH}" \
    --data-urlencode "releaseTrack=${RELEASE_TRACK}" \
    "${BEPLY_API_URL}/api/v1/plugins/release-witness"
}

verify_witness() {
  local body="$1"
  printf '%s' "$body" | python3 scripts/ci/verify_release_witness.py \
    --repository "$SOURCE_REPO_FULL_NAME" \
    --release-track "$RELEASE_TRACK" \
    --plugin-fs-name "$PLUGIN_FS_NAME" \
    --plugin-slug "$PLUGIN_SLUG" \
    --plugin-version "$PLUGIN_VERSION" \
    --source-branch "$SOURCE_BRANCH" \
    --source-tag "$SOURCE_RELEASE_TAG" \
    --source-url "$SOURCE_RELEASE_URL" \
    --source-published-at "$SOURCE_PUBLISHED_AT" \
    --checksum "$expected_checksum" \
    --file-size "$expected_size"
}

witness_response="$(query_witness)"
witness_http_code="${witness_response##*$'\n'}"
witness_body="${witness_response%$'\n'*}"
case "$witness_http_code" in
  200)
    verify_witness "$witness_body"
    echo "::notice::Exact lifecycle witness already exists; preserving immutable bytes."
    exit 0
    ;;
  404)
    ;;
  *)
    echo "::error::Release witness preflight failed (HTTP $witness_http_code): $witness_body" >&2
    exit 1
    ;;
esac

response="$(curl -sS -w $'\n%{http_code}' -X POST \
  -H "Authorization: Bearer ${BEPLY_CI_TOKEN}" \
  -F "file=@${PLUGIN_ZIP}" \
  -F "sourceRepoFullName=${SOURCE_REPO_FULL_NAME}" \
  -F "sourceBranch=${SOURCE_BRANCH}" \
  -F "releaseTrack=${RELEASE_TRACK}" \
  -F "sourceReleaseTag=${SOURCE_RELEASE_TAG}" \
  -F "sourceReleaseUrl=${SOURCE_RELEASE_URL}" \
  -F "sourcePublishedAt=${SOURCE_PUBLISHED_AT}" \
  "${BEPLY_API_URL}/api/v1/plugins/release")"
http_code="${response##*$'\n'}"
body="${response%$'\n'*}"

if [[ "$http_code" =~ ^2[0-9]{2}$ ]]; then
  printf '%s' "$body" | jq -e \
    --arg fs_name "$PLUGIN_FS_NAME" \
    --arg version "$PLUGIN_VERSION" \
    '.success == true and .data.pluginFsName == $fs_name and .data.version == $version' >/dev/null \
    || {
      echo "::error::Catalog upload response identity mismatch" >&2
      exit 1
    }
elif [ "$http_code" = "409" ]; then
  error_code="$(printf '%s' "$body" | jq -r '.error.code // .code // empty' 2>/dev/null || true)"
  if [ "$error_code" = "VERSION_ARTIFACT_IMMUTABLE" ] \
    || [ "$error_code" = "VERSION_ALREADY_APPROVED" ]; then
    witness_response="$(query_witness)"
    witness_http_code="${witness_response##*$'\n'}"
    witness_body="${witness_response%$'\n'*}"
    if [ "$witness_http_code" = "200" ]; then
      verify_witness "$witness_body"
      echo "::notice::Immutable POST race converged to the exact expected witness."
      exit 0
    fi
    echo "::error::Immutable POST race did not converge to the exact witness (HTTP $witness_http_code): $witness_body" >&2
    exit 1
  fi
  echo "::error::Catalog upload conflict cannot prove exact version ownership: $body" >&2
  exit 1
else
  echo "::error::Catalog upload failed (HTTP $http_code): $body" >&2
  exit 1
fi

for attempt in 1 2 3 4 5 6; do
  witness_response="$(query_witness)"
  witness_http_code="${witness_response##*$'\n'}"
  witness_body="${witness_response%$'\n'*}"
  if [ "$witness_http_code" = "200" ]; then
    verify_witness "$witness_body"
    echo "::notice::Catalog upload has one exact lifecycle-stable sink witness."
    exit 0
  fi
  if [ "$witness_http_code" != "404" ]; then
    echo "::error::Release witness readback failed (HTTP $witness_http_code): $witness_body" >&2
    exit 1
  fi
  if [ "$attempt" -lt 6 ]; then
    sleep "$((attempt * 5))"
  fi
done

echo "::error::Exact release witness was not readable after bounded retries." >&2
exit 1
