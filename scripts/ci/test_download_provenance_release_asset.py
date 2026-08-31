from __future__ import annotations

import hashlib
import tempfile
import unittest
import urllib.request
from pathlib import Path

from scripts.ci.download_provenance_release_asset import (
    DownloadPins,
    GitHubClient,
    ProvenanceError,
    SafeRedirectHandler,
    download_exact_provenance_asset,
)


SOURCE_SHA = "ad47505b4d780bd788901864591585863b250250"
PUBLISHED_AT = "2026-08-31T08:15:30Z"
PACKAGE = b"exact immutable BeplyPDFStudio plugin zip bytes"
DIGEST = f"sha256:{hashlib.sha256(PACKAGE).hexdigest()}"


class FakeClient:
    def __init__(
        self,
        *,
        package: bytes = PACKAGE,
        tag_sha: str = SOURCE_SHA,
        duplicate: bool = False,
        asset_id: int = 533447592,
        reported_size: int | None = None,
        published_at: str | None = PUBLISHED_AT,
    ) -> None:
        self.package = package
        self.tag_sha = tag_sha
        self.duplicate = duplicate
        self.asset_id = asset_id
        self.reported_size = len(PACKAGE) if reported_size is None else reported_size
        self.published_at = published_at
        self.calls: list[tuple[str, str]] = []
        self.assert_accept = ""

    def json(self, path: str):
        self.calls.append(("json", path))
        if "/git/ref/tags/" in path:
            return {"object": {"type": "commit", "sha": self.tag_sha}}
        if "/releases/tags/" in path:
            asset = {
                "id": self.asset_id,
                "name": "BeplyPDFStudio-dev-3.4.zip",
                "digest": DIGEST,
                "size": self.reported_size,
            }
            return {
                "published_at": self.published_at,
                "assets": [asset, dict(asset)] if self.duplicate else [asset],
            }
        raise AssertionError(path)

    def bytes(self, path: str, *, accept: str):
        self.calls.append(("bytes", path))
        self.assert_accept = accept
        return self.package


class ProvenanceDownloadTests(unittest.TestCase):
    def pins(self, destination: Path) -> DownloadPins:
        return DownloadPins(
            repository="beply-es/BeplyPDFStudio",
            source_tag=f"main-{SOURCE_SHA}",
            source_sha=SOURCE_SHA,
            asset_name="BeplyPDFStudio-dev-3.4.zip",
            destination=destination,
            attempts=2,
        )

    def test_downloads_exact_asset_and_replaces_a_different_local_rebuild(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            destination = Path(tmp_dir) / "BeplyPDFStudio-v3.4.zip"
            destination.write_bytes(b"different rerun bytes")
            client = FakeClient()

            result = download_exact_provenance_asset(
                self.pins(destination),
                client=client,
                sleep=lambda _seconds: None,
            )

            self.assertEqual(destination.read_bytes(), PACKAGE)
            self.assertEqual(result.sha256, DIGEST)
            self.assertEqual(result.size, len(PACKAGE))
            self.assertEqual(result.asset_id, 533447592)
            self.assertEqual(result.published_at, PUBLISHED_AT)
            self.assertEqual(client.assert_accept, "application/octet-stream")

    def test_rejects_tag_target_drift_without_downloading(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            client = FakeClient(tag_sha="0" * 40)
            with self.assertRaisesRegex(ProvenanceError, "points to"):
                download_exact_provenance_asset(
                    self.pins(Path(tmp_dir) / "plugin.zip"),
                    client=client,
                    sleep=lambda _seconds: None,
                )
            self.assertFalse(any(kind == "bytes" for kind, _path in client.calls))

    def test_fails_closed_on_duplicate_asset_identity(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            with self.assertRaisesRegex(ProvenanceError, "exact assets"):
                download_exact_provenance_asset(
                    self.pins(Path(tmp_dir) / "plugin.zip"),
                    client=FakeClient(duplicate=True),
                    sleep=lambda _seconds: None,
                )

    def test_rejects_unready_asset_id_without_downloading(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            client = FakeClient(asset_id=0)
            with self.assertRaisesRegex(ProvenanceError, "asset id"):
                download_exact_provenance_asset(
                    self.pins(Path(tmp_dir) / "plugin.zip"),
                    client=client,
                    sleep=lambda _seconds: None,
                )
            self.assertFalse(any(kind == "bytes" for kind, _path in client.calls))

    def test_rejects_missing_release_published_at_before_downloading(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            client = FakeClient(published_at=None)
            with self.assertRaisesRegex(ProvenanceError, "published_at"):
                download_exact_provenance_asset(
                    self.pins(Path(tmp_dir) / "plugin.zip"),
                    client=client,
                    sleep=lambda _seconds: None,
                )
            self.assertFalse(any(kind == "bytes" for kind, _path in client.calls))

    def test_rejects_malformed_release_published_at_before_downloading(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            client = FakeClient(published_at="2026-08-31 08:15:30")
            with self.assertRaisesRegex(ProvenanceError, "published_at"):
                download_exact_provenance_asset(
                    self.pins(Path(tmp_dir) / "plugin.zip"),
                    client=client,
                    sleep=lambda _seconds: None,
                )
            self.assertFalse(any(kind == "bytes" for kind, _path in client.calls))

    def test_rejects_downloaded_digest_drift(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            with self.assertRaisesRegex(ProvenanceError, "digest"):
                download_exact_provenance_asset(
                    self.pins(Path(tmp_dir) / "plugin.zip"),
                    client=FakeClient(package=b"tampered"),
                    sleep=lambda _seconds: None,
                )

    def test_rejects_downloaded_size_drift(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            with self.assertRaisesRegex(ProvenanceError, "size"):
                download_exact_provenance_asset(
                    self.pins(Path(tmp_dir) / "plugin.zip"),
                    client=FakeClient(reported_size=len(PACKAGE) + 1),
                    sleep=lambda _seconds: None,
                )

    def test_cross_host_redirect_strips_github_authorization(self) -> None:
        handler = SafeRedirectHandler()
        original = urllib.request.Request(
            "https://api.github.com/repos/beply-es/BeplyPDFStudio/releases/assets/1",
            headers={"Authorization": "Bearer hidden", "Accept": "application/octet-stream"},
        )
        redirected = handler.redirect_request(
            original,
            None,
            302,
            "Found",
            {},
            "https://objects.githubusercontent.com/signed",
        )

        self.assertIsNotNone(redirected)
        self.assertIsNone(redirected.get_header("Authorization"))

    def test_client_requires_a_token(self) -> None:
        with self.assertRaisesRegex(ProvenanceError, "token"):
            GitHubClient("")


if __name__ == "__main__":
    unittest.main()
