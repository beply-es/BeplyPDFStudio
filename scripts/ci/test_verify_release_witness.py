from __future__ import annotations

import unittest

from scripts.ci.verify_release_witness import (
    WitnessMismatch,
    WitnessPins,
    verify_release_witness,
)


CHECKSUM = "sha256:" + "a" * 64
PUBLISHED_AT = "2026-08-31T08:15:30.000Z"
SOURCE_TAG = "main-" + "1" * 40
SOURCE_URL = f"https://github.com/beply-es/BeplyPDFStudio/releases/tag/{SOURCE_TAG}"


def witness(**changes):
    value = {
        "claimed": True,
        "witnessComplete": True,
        "artifactPluginSlug": "beplypdfstudio",
        "pluginId": "plugin-pdf",
        "versionId": "version-pdf-34",
        "pluginSlug": "beplypdfstudio",
        "pluginFsName": "BeplyPDFStudio",
        "version": "3.4",
        "releaseTrack": "main",
        "releaseStatus": "pending_review",
        "sourceRepoFullName": "beply-es/BeplyPDFStudio",
        "sourceBranch": "main",
        "sourceReleaseTag": SOURCE_TAG,
        "sourceReleaseUrl": SOURCE_URL,
        "sourcePublishedAt": PUBLISHED_AT,
        "checksum": CHECKSUM,
        "fileSize": 4321,
    }
    value.update(changes)
    return value


def payload(**changes):
    return {"success": True, "data": {"witness": witness(**changes)}}


class ReleaseWitnessTests(unittest.TestCase):
    def pins(self, **changes) -> WitnessPins:
        values = {
            "repository": "beply-es/BeplyPDFStudio",
            "release_track": "main",
            "plugin_fs_name": "BeplyPDFStudio",
            "plugin_slug": "beplypdfstudio",
            "plugin_version": "3.4",
            "source_branch": "main",
            "source_tag": SOURCE_TAG,
            "source_url": SOURCE_URL,
            "source_published_at": "2026-08-31T08:15:30Z",
            "checksum": CHECKSUM,
            "file_size": 4321,
        }
        values.update(changes)
        return WitnessPins(**values)

    def test_accepts_one_exact_pending_or_approved_lifecycle_witness(self) -> None:
        pending = verify_release_witness(payload(), self.pins())
        approved = verify_release_witness(
            payload(releaseStatus="approved"),
            self.pins(),
        )

        self.assertEqual(pending.release_status, "pending_review")
        self.assertEqual(approved.release_status, "approved")
        self.assertEqual(pending.plugin_id, "plugin-pdf")
        self.assertEqual(pending.version_id, "version-pdf-34")

    def test_rejects_any_causal_provenance_or_artifact_mismatch(self) -> None:
        changes = {
            "artifactPluginSlug": "other-artifact",
            "pluginSlug": "other-plugin",
            "pluginFsName": "Diseñador PDF",
            "version": "3.3",
            "releaseTrack": "beta",
            "sourceRepoFullName": "beply-es/other",
            "sourceBranch": "legacy",
            "sourceReleaseTag": "other-tag",
            "sourceReleaseUrl": "https://github.com/beply-es/other/releases/tag/other-tag",
            "sourcePublishedAt": "2026-08-31T08:15:31.000Z",
            "checksum": "sha256:" + "b" * 64,
            "fileSize": 4322,
        }
        for field, value in changes.items():
            with self.subTest(field=field), self.assertRaisesRegex(WitnessMismatch, field):
                verify_release_witness(payload(**{field: value}), self.pins())

    def test_requires_exact_backend_ids_and_complete_witness(self) -> None:
        for field in ("pluginId", "versionId"):
            with self.subTest(field=field), self.assertRaisesRegex(WitnessMismatch, field):
                verify_release_witness(payload(**{field: ""}), self.pins())
        with self.assertRaisesRegex(WitnessMismatch, "witnessComplete"):
            verify_release_witness(payload(witnessComplete=False), self.pins())

    def test_rejects_non_installable_lifecycle_statuses(self) -> None:
        for status in ("rejected", "deprecated", "deleted"):
            with self.subTest(status=status), self.assertRaisesRegex(WitnessMismatch, "releaseStatus"):
                verify_release_witness(payload(releaseStatus=status), self.pins())

    def test_repository_and_url_owner_are_case_insensitive_but_tag_is_exact(self) -> None:
        result = verify_release_witness(
            payload(
                sourceRepoFullName="BEPLY-ES/BEPLYPDFSTUDIO",
                sourceReleaseUrl=f"HTTPS://GITHUB.COM/BEPLY-ES/BEPLYPDFSTUDIO/releases/tag/{SOURCE_TAG}",
            ),
            self.pins(),
        )
        self.assertEqual(result.source_release_tag, SOURCE_TAG)

        with self.assertRaisesRegex(WitnessMismatch, "sourceReleaseUrl"):
            verify_release_witness(
                payload(sourceReleaseUrl=SOURCE_URL.replace("main-", "MAIN-")),
                self.pins(),
            )

    def test_rejects_invalid_response_envelope(self) -> None:
        with self.assertRaisesRegex(WitnessMismatch, "response envelope"):
            verify_release_witness({"success": True, "data": []}, self.pins())


if __name__ == "__main__":
    unittest.main()
