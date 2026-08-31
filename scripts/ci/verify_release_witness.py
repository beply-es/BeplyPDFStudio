#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
import sys
import urllib.parse
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any


CHECKSUM_PATTERN = re.compile(r"^sha256:[0-9a-f]{64}$")
SAFE_ID_PATTERN = re.compile(r"^[A-Za-z0-9._:-]+$")
EXACT_RELEASE_STATUSES = {"pending_review", "approved"}


class WitnessError(RuntimeError):
    pass


class WitnessMismatch(WitnessError):
    pass


def normalize_timestamp(value: str, field: str) -> str:
    if not value:
        raise WitnessMismatch(f"{field} must not be empty")
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise WitnessMismatch(f"{field} is not valid ISO UTC: {value}") from exc
    if parsed.tzinfo is None:
        raise WitnessMismatch(f"{field} must include a timezone")
    return parsed.astimezone(timezone.utc).isoformat(timespec="milliseconds").replace(
        "+00:00",
        "Z",
    )


def github_release_url_matches(actual: str, expected: str) -> bool:
    try:
        actual_url = urllib.parse.urlsplit(actual)
        expected_url = urllib.parse.urlsplit(expected)
        actual_parts = [part for part in actual_url.path.split("/") if part]
        expected_parts = [part for part in expected_url.path.split("/") if part]
    except ValueError:
        return False
    if len(actual_parts) != len(expected_parts) or not actual_parts:
        return False
    return (
        actual_url.scheme.casefold() == expected_url.scheme.casefold()
        and actual_url.netloc.casefold() == expected_url.netloc.casefold()
        and actual_url.query == expected_url.query
        and actual_url.fragment == expected_url.fragment
        and all(
            actual_part.casefold() == expected_part.casefold()
            for actual_part, expected_part in zip(
                actual_parts[:-1],
                expected_parts[:-1],
            )
        )
        and actual_parts[-1] == expected_parts[-1]
    )


@dataclass(frozen=True)
class WitnessPins:
    repository: str
    release_track: str
    plugin_fs_name: str
    plugin_slug: str
    plugin_version: str
    source_branch: str
    source_tag: str
    source_url: str
    source_published_at: str
    checksum: str
    file_size: int

    def validate(self) -> None:
        required = {
            "repository": self.repository,
            "release_track": self.release_track,
            "plugin_fs_name": self.plugin_fs_name,
            "plugin_slug": self.plugin_slug,
            "plugin_version": self.plugin_version,
            "source_branch": self.source_branch,
            "source_tag": self.source_tag,
            "source_url": self.source_url,
            "source_published_at": self.source_published_at,
            "checksum": self.checksum,
        }
        missing = [name for name, value in required.items() if not value]
        if missing:
            raise WitnessMismatch(f"witness pins are missing: {', '.join(missing)}")
        if not SAFE_ID_PATTERN.fullmatch(self.plugin_slug):
            raise WitnessMismatch("pluginSlug must be exact safe catalog identity")
        if not CHECKSUM_PATTERN.fullmatch(self.checksum):
            raise WitnessMismatch("checksum must be exact sha256:<64 lowercase hex>")
        if self.file_size < 1:
            raise WitnessMismatch("fileSize must be a positive integer")
        normalize_timestamp(self.source_published_at, "sourcePublishedAt")


@dataclass(frozen=True)
class WitnessResult:
    plugin_id: str
    version_id: str
    plugin_slug: str
    plugin_fs_name: str
    version: str
    release_track: str
    release_status: str
    source_repo_full_name: str
    source_branch: str
    source_release_tag: str
    source_release_url: str
    source_published_at: str
    checksum: str
    file_size: int


def _witness(payload: dict[str, Any]) -> dict[str, Any]:
    data = payload.get("data")
    if payload.get("success") is not True or not isinstance(data, dict):
        raise WitnessMismatch("release-witness response envelope is invalid")
    witness = data.get("witness")
    if not isinstance(witness, dict):
        raise WitnessMismatch("release-witness response has no witness object")
    return witness


def _safe_string(witness: dict[str, Any], field: str) -> str:
    value = witness.get(field)
    if not isinstance(value, str) or not SAFE_ID_PATTERN.fullmatch(value):
        raise WitnessMismatch(f"release witness has no safe {field}")
    return value


def verify_release_witness(
    payload: dict[str, Any],
    pins: WitnessPins,
) -> WitnessResult:
    pins.validate()
    witness = _witness(payload)
    if witness.get("claimed") is not True:
        raise WitnessMismatch("release witness must assert claimed=true")
    if witness.get("witnessComplete") is not True:
        raise WitnessMismatch("release witness must assert witnessComplete=true")

    plugin_id = _safe_string(witness, "pluginId")
    version_id = _safe_string(witness, "versionId")
    plugin_slug = _safe_string(witness, "pluginSlug")
    artifact_plugin_slug = _safe_string(witness, "artifactPluginSlug")
    version = _safe_string(witness, "version")
    release_track = _safe_string(witness, "releaseTrack")
    release_status = _safe_string(witness, "releaseStatus")

    expected = {
        "artifactPluginSlug": pins.plugin_slug,
        "pluginSlug": pins.plugin_slug,
        "pluginFsName": pins.plugin_fs_name,
        "version": pins.plugin_version,
        "releaseTrack": pins.release_track,
        "sourceBranch": pins.source_branch,
        "sourceReleaseTag": pins.source_tag,
        "checksum": pins.checksum,
        "fileSize": pins.file_size,
    }
    mismatches = [field for field, value in expected.items() if witness.get(field) != value]

    actual_repository = witness.get("sourceRepoFullName")
    if not isinstance(actual_repository, str) or (
        actual_repository.casefold() != pins.repository.casefold()
    ):
        mismatches.append("sourceRepoFullName")

    actual_url = witness.get("sourceReleaseUrl")
    if not isinstance(actual_url, str) or not github_release_url_matches(
        actual_url,
        pins.source_url,
    ):
        mismatches.append("sourceReleaseUrl")

    source_published_at = normalize_timestamp(
        str(witness.get("sourcePublishedAt") or ""),
        "sourcePublishedAt",
    )
    if source_published_at != normalize_timestamp(
        pins.source_published_at,
        "sourcePublishedAt",
    ):
        mismatches.append("sourcePublishedAt")

    if release_status not in EXACT_RELEASE_STATUSES:
        mismatches.append("releaseStatus")
    if mismatches:
        raise WitnessMismatch(
            "release witness tuple differs at: " + ", ".join(dict.fromkeys(mismatches))
        )

    return WitnessResult(
        plugin_id=plugin_id,
        version_id=version_id,
        plugin_slug=plugin_slug,
        plugin_fs_name=str(witness["pluginFsName"]),
        version=version,
        release_track=release_track,
        release_status=release_status,
        source_repo_full_name=actual_repository,
        source_branch=str(witness["sourceBranch"]),
        source_release_tag=str(witness["sourceReleaseTag"]),
        source_release_url=actual_url,
        source_published_at=source_published_at,
        checksum=str(witness["checksum"]),
        file_size=int(witness["fileSize"]),
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Verify one exact lifecycle-stable plugin release witness."
    )
    parser.add_argument("--repository", required=True)
    parser.add_argument("--release-track", required=True)
    parser.add_argument("--plugin-fs-name", required=True)
    parser.add_argument("--plugin-slug", required=True)
    parser.add_argument("--plugin-version", required=True)
    parser.add_argument("--source-branch", required=True)
    parser.add_argument("--source-tag", required=True)
    parser.add_argument("--source-url", required=True)
    parser.add_argument("--source-published-at", required=True)
    parser.add_argument("--checksum", required=True)
    parser.add_argument("--file-size", type=int, required=True)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    try:
        payload = json.load(sys.stdin)
        if not isinstance(payload, dict):
            raise WitnessMismatch("release-witness response must be a JSON object")
        result = verify_release_witness(
            payload,
            WitnessPins(
                repository=args.repository,
                release_track=args.release_track,
                plugin_fs_name=args.plugin_fs_name,
                plugin_slug=args.plugin_slug,
                plugin_version=args.plugin_version,
                source_branch=args.source_branch,
                source_tag=args.source_tag,
                source_url=args.source_url,
                source_published_at=args.source_published_at,
                checksum=args.checksum,
                file_size=args.file_size,
            ),
        )
        print(
            "Release witness: "
            f"pluginId={result.plugin_id} versionId={result.version_id} "
            f"fsName={result.plugin_fs_name} version={result.version} "
            f"track={result.release_track} status={result.release_status}"
        )
        return 0
    except (WitnessError, json.JSONDecodeError, OSError) as exc:
        print(f"[verify-release-witness] {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
