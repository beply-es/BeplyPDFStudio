from __future__ import annotations

import hashlib
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
PACKAGE = b"BeplyPDFStudio exact catalog bytes"
CHECKSUM = "sha256:" + hashlib.sha256(PACKAGE).hexdigest()
PUBLISHED_AT = "2026-08-31T08:15:30Z"
SOURCE_TAG = "main-" + "1" * 40
SOURCE_URL = f"https://github.com/beply-es/BeplyPDFStudio/releases/tag/{SOURCE_TAG}"


class CatalogUploadShellTests(unittest.TestCase):
    def run_script(
        self,
        *,
        existing: bool,
        release_status: str = "pending_review",
        post_conflict: str | None = None,
        response_fs_name: str = "BeplyPDFStudio",
    ):
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = Path(tmp_dir)
            zip_path = tmp / "BeplyPDFStudio-dev-3.4.zip"
            zip_path.write_bytes(PACKAGE)
            state = tmp / "state"
            state.write_text("1" if existing else "0")
            calls = tmp / "calls"
            row = {
                "claimed": True,
                "witnessComplete": True,
                "artifactPluginSlug": "beplypdfstudio",
                "pluginId": "plugin-pdf",
                "versionId": "version-pdf-34",
                "pluginSlug": "beplypdfstudio",
                "pluginFsName": "BeplyPDFStudio",
                "version": "3.4",
                "releaseTrack": "main",
                "releaseStatus": release_status,
                "sourceRepoFullName": "beply-es/BeplyPDFStudio",
                "sourceBranch": "main",
                "sourceReleaseTag": SOURCE_TAG,
                "sourceReleaseUrl": SOURCE_URL,
                "sourcePublishedAt": "2026-08-31T08:15:30.000Z",
                "checksum": CHECKSUM,
                "fileSize": len(PACKAGE),
            }
            fake_bin = tmp / "bin"
            fake_bin.mkdir()
            fake_curl = fake_bin / "curl"
            fake_curl.write_text(
                "#!/usr/bin/env python3\n"
                "import json, os, sys\n"
                "from pathlib import Path\n"
                "state = Path(os.environ['FAKE_CURL_STATE'])\n"
                "calls = Path(os.environ['FAKE_CURL_CALLS'])\n"
                "args = sys.argv[1:]\n"
                "is_post = '-X' in args and args[args.index('-X') + 1] == 'POST'\n"
                "with calls.open('a') as handle: handle.write(('POST' if is_post else 'GET') + '\\n')\n"
                "if is_post:\n"
                "    forms = [args[index + 1] for index, arg in enumerate(args[:-1]) if arg == '-F']\n"
                "    required = {\n"
                "        'sourceRepoFullName=beply-es/BeplyPDFStudio',\n"
                "        'sourceBranch=main',\n"
                "        'releaseTrack=main',\n"
                "        'sourceReleaseTag=' + os.environ['EXPECTED_SOURCE_TAG'],\n"
                "        'sourceReleaseUrl=' + os.environ['EXPECTED_SOURCE_URL'],\n"
                "        'sourcePublishedAt=' + os.environ['EXPECTED_PUBLISHED_AT'],\n"
                "    }\n"
                "    if not required.issubset(set(forms)):\n"
                "        print(json.dumps({'success': False, 'error': {'code': 'MISSING_PROVENANCE_FIELDS'}}))\n"
                "        print('400')\n"
                "        raise SystemExit(0)\n"
                "    state.write_text('1')\n"
                "    conflict = os.environ.get('FAKE_POST_CONFLICT', '')\n"
                "    if conflict:\n"
                "        print(json.dumps({'success': False, 'error': {'code': conflict}}))\n"
                "        print('409')\n"
                "    else:\n"
                "        print(json.dumps({'success': True, 'data': {\n"
                "            'pluginName': 'Diseñador PDF',\n"
                "            'pluginFsName': os.environ['FAKE_RESPONSE_FS_NAME'],\n"
                "            'version': '3.4',\n"
                "            'releaseStatus': 'pending_review',\n"
                "        }}))\n"
                "        print('201')\n"
                "elif state.read_text() == '1':\n"
                "    print(json.dumps({'success': True, 'data': {'witness': json.loads(os.environ['FAKE_WITNESS'])}}))\n"
                "    print('200')\n"
                "else:\n"
                "    print(json.dumps({'success': False, 'error': {'code': 'RELEASE_WITNESS_NOT_FOUND'}}))\n"
                "    print('404')\n"
            )
            fake_curl.chmod(0o755)
            env = os.environ.copy()
            env.update(
                {
                    "PATH": f"{fake_bin}:{env['PATH']}",
                    "FAKE_CURL_STATE": str(state),
                    "FAKE_CURL_CALLS": str(calls),
                    "FAKE_WITNESS": json.dumps(row),
                    "FAKE_POST_CONFLICT": post_conflict or "",
                    "FAKE_RESPONSE_FS_NAME": response_fs_name,
                    "EXPECTED_SOURCE_TAG": SOURCE_TAG,
                    "EXPECTED_SOURCE_URL": SOURCE_URL,
                    "EXPECTED_PUBLISHED_AT": PUBLISHED_AT,
                    "BEPLY_API_URL": "https://api.invalid",
                    "BEPLY_CI_TOKEN": "test-token",
                    "PLUGIN_ZIP": str(zip_path),
                    "PLUGIN_FS_NAME": "BeplyPDFStudio",
                    "PLUGIN_SLUG": "beplypdfstudio",
                    "PLUGIN_VERSION": "3.4",
                    "SOURCE_REPO_FULL_NAME": "beply-es/BeplyPDFStudio",
                    "SOURCE_BRANCH": "main",
                    "RELEASE_TRACK": "main",
                    "SOURCE_RELEASE_TAG": SOURCE_TAG,
                    "SOURCE_RELEASE_URL": SOURCE_URL,
                    "SOURCE_PUBLISHED_AT": PUBLISHED_AT,
                    "EXPECTED_CHECKSUM": CHECKSUM,
                    "EXPECTED_FILE_SIZE": str(len(PACKAGE)),
                }
            )
            result = subprocess.run(
                ["bash", "scripts/ci/upload_catalog_release.sh"],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )
            call_lines = calls.read_text().splitlines() if calls.exists() else []
            return result, call_lines

    def test_404_posts_all_provenance_then_requires_exact_witness(self) -> None:
        result, calls = self.run_script(existing=False)

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(calls, ["GET", "POST", "GET"])

    def test_existing_approved_witness_is_idempotent_without_post(self) -> None:
        result, calls = self.run_script(existing=True, release_status="approved")

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(calls, ["GET"])

    def test_response_uses_plugin_fs_name_not_display_name(self) -> None:
        accepted, accepted_calls = self.run_script(existing=False)
        rejected, rejected_calls = self.run_script(
            existing=False,
            response_fs_name="Diseñador PDF",
        )

        self.assertEqual(accepted.returncode, 0, accepted.stderr)
        self.assertEqual(accepted_calls, ["GET", "POST", "GET"])
        self.assertNotEqual(rejected.returncode, 0)
        self.assertEqual(rejected_calls, ["GET", "POST"])
        self.assertIn("response identity mismatch", rejected.stderr)

    def test_only_immutable_version_conflicts_can_converge_through_witness(self) -> None:
        for code in ("VERSION_ARTIFACT_IMMUTABLE", "VERSION_ALREADY_APPROVED"):
            with self.subTest(code=code):
                result, calls = self.run_script(existing=False, post_conflict=code)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(calls, ["GET", "POST", "GET"])

    def test_submission_pending_and_unrelated_conflicts_fail_without_witness_fallback(self) -> None:
        for code in ("SUBMISSION_PENDING", "REPOSITORY_MISMATCH"):
            with self.subTest(code=code):
                result, calls = self.run_script(existing=False, post_conflict=code)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(calls, ["GET", "POST"])


if __name__ == "__main__":
    unittest.main()
