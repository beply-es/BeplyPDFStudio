#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import socket
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable, Protocol


SHA_PATTERN = re.compile(r"^[0-9a-f]{40}$")
DIGEST_PATTERN = re.compile(r"^sha256:[0-9a-f]{64}$")
REPOSITORY_PATTERN = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")
ASSET_PATTERN = re.compile(r"^[A-Za-z0-9_.-]+\.zip$")
PUBLISHED_AT_PATTERN = re.compile(r"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$")
RETRYABLE_HTTP_CODES = {429, 500, 502, 503, 504}


class ProvenanceError(RuntimeError):
    pass


class ProvenanceNotReady(ProvenanceError):
    pass


class SafeRedirectHandler(urllib.request.HTTPRedirectHandler):
    def redirect_request(
        self,
        req: urllib.request.Request,
        fp: Any,
        code: int,
        msg: str,
        headers: Any,
        newurl: str,
    ) -> urllib.request.Request | None:
        redirected = super().redirect_request(req, fp, code, msg, headers, newurl)
        if redirected is None:
            return None
        source_host = urllib.parse.urlparse(req.full_url).netloc.casefold()
        target_host = urllib.parse.urlparse(newurl).netloc.casefold()
        if source_host != target_host:
            redirected.remove_header("Authorization")
        return redirected


@dataclass(frozen=True)
class DownloadPins:
    repository: str
    source_tag: str
    source_sha: str
    asset_name: str
    destination: Path
    attempts: int = 6

    def validate(self) -> None:
        if not REPOSITORY_PATTERN.fullmatch(self.repository):
            raise ProvenanceError("repository must use exact owner/name format")
        if not self.repository.startswith("beply-es/"):
            raise ProvenanceError("repository must belong to beply-es")
        if self.source_tag != f"main-{self.source_sha}":
            raise ProvenanceError("source tag must bind the full source SHA")
        if not SHA_PATTERN.fullmatch(self.source_sha):
            raise ProvenanceError("source SHA must be exact lowercase 40-character hex")
        if not ASSET_PATTERN.fullmatch(self.asset_name):
            raise ProvenanceError("asset name must be one root-level ZIP filename")
        if self.destination.name in {"", ".", ".."}:
            raise ProvenanceError("destination filename is invalid")
        if self.attempts < 1 or self.attempts > 10:
            raise ProvenanceError("attempts must be between 1 and 10")


@dataclass(frozen=True)
class DownloadResult:
    asset_id: int
    sha256: str
    size: int
    published_at: str
    destination: Path


class ProvenanceClient(Protocol):
    def json(self, path: str) -> dict[str, Any]: ...

    def bytes(self, path: str, *, accept: str) -> bytes: ...


class GitHubClient:
    def __init__(self, token: str, *, api_url: str = "https://api.github.com") -> None:
        if not token:
            raise ProvenanceError("GitHub token is required")
        self.token = token
        self.api_url = api_url.rstrip("/")
        self.opener = urllib.request.build_opener(SafeRedirectHandler())

    def _request(self, path: str, *, accept: str) -> bytes:
        request = urllib.request.Request(
            f"{self.api_url}{path}",
            headers={
                "Accept": accept,
                "Authorization": f"Bearer {self.token}",
                "User-Agent": "beplypdfstudio-exact-release-reuse",
                "X-GitHub-Api-Version": "2022-11-28",
            },
        )
        with self.opener.open(request, timeout=90) as response:
            return response.read()

    def json(self, path: str) -> dict[str, Any]:
        try:
            payload = json.loads(self._request(path, accept="application/vnd.github+json"))
        except json.JSONDecodeError as exc:
            raise ProvenanceError("GitHub API returned invalid JSON") from exc
        if not isinstance(payload, dict):
            raise ProvenanceError("GitHub API returned an invalid object")
        return payload

    def bytes(self, path: str, *, accept: str) -> bytes:
        return self._request(path, accept=accept)


def _tag_commit_sha(client: ProvenanceClient, pins: DownloadPins) -> str:
    encoded_tag = urllib.parse.quote(pins.source_tag, safe="")
    ref = client.json(f"/repos/{pins.repository}/git/ref/tags/{encoded_tag}")
    tag_object = ref.get("object")
    if not isinstance(tag_object, dict):
        raise ProvenanceError("DEV provenance tag has no object identity")
    tag_sha = str(tag_object.get("sha") or "").lower()
    if tag_object.get("type") == "tag":
        annotated = client.json(f"/repos/{pins.repository}/git/tags/{tag_sha}")
        annotated_object = annotated.get("object")
        if not isinstance(annotated_object, dict):
            raise ProvenanceError("Annotated DEV provenance tag has no commit identity")
        tag_sha = str(annotated_object.get("sha") or "").lower()
    return tag_sha


def _fetch_once(client: ProvenanceClient, pins: DownloadPins) -> DownloadResult:
    tag_sha = _tag_commit_sha(client, pins)
    if tag_sha != pins.source_sha:
        raise ProvenanceError(
            f"DEV provenance tag {pins.source_tag} points to {tag_sha}, expected {pins.source_sha}"
        )

    encoded_tag = urllib.parse.quote(pins.source_tag, safe="")
    release = client.json(f"/repos/{pins.repository}/releases/tags/{encoded_tag}")
    published_at = str(release.get("published_at") or "")
    if not PUBLISHED_AT_PATTERN.fullmatch(published_at):
        raise ProvenanceNotReady("DEV provenance release published_at is not ready")
    assets = release.get("assets")
    if not isinstance(assets, list):
        raise ProvenanceNotReady("DEV provenance release has no asset inventory")
    matches = [
        asset
        for asset in assets
        if isinstance(asset, dict) and asset.get("name") == pins.asset_name
    ]
    if len(matches) != 1:
        raise ProvenanceNotReady(
            f"DEV provenance release has {len(matches)} exact assets named {pins.asset_name}"
        )

    asset = matches[0]
    asset_id = asset.get("id")
    digest = str(asset.get("digest") or "").lower()
    size = asset.get("size")
    if not isinstance(asset_id, int) or asset_id < 1:
        raise ProvenanceNotReady("DEV provenance asset id is not ready")
    if not DIGEST_PATTERN.fullmatch(digest):
        raise ProvenanceNotReady("DEV provenance asset digest is not ready")
    if not isinstance(size, int) or size < 1:
        raise ProvenanceNotReady("DEV provenance asset size is not ready")

    package = client.bytes(
        f"/repos/{pins.repository}/releases/assets/{asset_id}",
        accept="application/octet-stream",
    )
    actual_digest = f"sha256:{hashlib.sha256(package).hexdigest()}"
    if actual_digest != digest:
        raise ProvenanceError(
            f"Downloaded DEV ZIP digest {actual_digest} does not match GitHub {digest}"
        )
    if len(package) != size:
        raise ProvenanceError(
            f"Downloaded DEV ZIP size {len(package)} does not match GitHub {size}"
        )

    pins.destination.parent.mkdir(parents=True, exist_ok=True)
    temporary = pins.destination.with_suffix(pins.destination.suffix + ".download")
    temporary.write_bytes(package)
    temporary.replace(pins.destination)
    return DownloadResult(
        asset_id=asset_id,
        sha256=actual_digest,
        size=len(package),
        published_at=published_at,
        destination=pins.destination,
    )


def download_exact_provenance_asset(
    pins: DownloadPins,
    *,
    client: ProvenanceClient,
    sleep: Callable[[float], None] = time.sleep,
) -> DownloadResult:
    pins.validate()
    last_missing: str | None = None
    for attempt in range(1, pins.attempts + 1):
        try:
            return _fetch_once(client, pins)
        except urllib.error.HTTPError as exc:
            if exc.code == 404:
                last_missing = f"GitHub API returned 404 for {exc.url}"
            elif exc.code in RETRYABLE_HTTP_CODES:
                last_missing = f"GitHub API returned transient HTTP {exc.code} for {exc.url}"
            else:
                raise ProvenanceError(f"GitHub API failed with HTTP {exc.code}") from exc
        except urllib.error.URLError as exc:
            last_missing = f"GitHub request failed transiently: {exc.reason}"
        except (TimeoutError, socket.timeout) as exc:
            last_missing = f"GitHub request timed out transiently: {exc}"
        except ProvenanceNotReady as exc:
            last_missing = str(exc)

        if attempt < pins.attempts:
            sleep(attempt * 5)

    raise ProvenanceError(
        f"Exact DEV provenance asset {pins.source_tag}/{pins.asset_name} was not available "
        f"after bounded retries: {last_missing}"
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Download one exact immutable DEV provenance ZIP through GitHub API."
    )
    parser.add_argument("--repository", required=True)
    parser.add_argument("--source-tag", required=True)
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--asset-name", required=True)
    parser.add_argument("--destination", type=Path, required=True)
    parser.add_argument("--attempts", type=int, default=6)
    parser.add_argument("--github-output", type=Path)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    pins = DownloadPins(
        repository=args.repository,
        source_tag=args.source_tag,
        source_sha=args.source_sha.lower(),
        asset_name=args.asset_name,
        destination=args.destination,
        attempts=args.attempts,
    )
    try:
        result = download_exact_provenance_asset(
            pins,
            client=GitHubClient(os.environ.get("GITHUB_TOKEN", "")),
        )
        if args.github_output:
            with args.github_output.open("a", encoding="utf-8") as output:
                output.write(f"zip_path={result.destination}\n")
                output.write(f"asset_id={result.asset_id}\n")
                output.write(f"sha256={result.sha256}\n")
                output.write(f"size={result.size}\n")
                output.write(f"published_at={result.published_at}\n")
        print(
            "Exact DEV provenance reused: "
            f"tag={pins.source_tag} assetId={result.asset_id} "
            f"digest={result.sha256} size={result.size} publishedAt={result.published_at}"
        )
        return 0
    except Exception as exc:  # noqa: BLE001
        print(f"[download-provenance-release-asset] {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
