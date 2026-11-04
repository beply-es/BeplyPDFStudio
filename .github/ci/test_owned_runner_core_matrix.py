"""Source gates for the inactive Studio CI composition; not runner isolation proof."""
import importlib.util
import os
import subprocess
import tempfile
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('workflow_triggers', ROOT / '.github/ci/test_tests_workflow_triggers.py')
triggers = importlib.util.module_from_spec(spec)
spec.loader.exec_module(triggers)
EXPECTED = [
    ('2025.71', 'b4f2422e4a3f5931c7188214fb8e2196b80182b5'),
    ('2026.65', '35eeafaf1f9aeb630f7f3bb995cdf6ccc522bb8f'),
    ('2026.72', 'e38f65a60e46bd2aba6bf5e3c4e3e1ad8c4444cc'),
]


class OwnedRunnerCoreMatrixTest(unittest.TestCase):
    def setUp(self):
        self.tests = (ROOT / '.github/workflows/tests.yml').read_text()
        self.release = (ROOT / '.github/workflows/release.yml').read_text()
        self.runtime = triggers.job_blocks(self.tests)['runtime']

    def test_all_three_official_core_versions_have_exact_commits(self):
        rows = re.findall(r"          - core_version: '([^']+)'\n            core_sha: ([0-9a-f]{40})\n", self.runtime)
        self.assertEqual(rows, EXPECTED)
        self.assertIn('      fail-fast: false\n', self.runtime)
        self.assertNotIn('exclude:', self.runtime)

    def test_core_checkout_consumes_the_immutable_matrix_sha(self):
        checkout = self.runtime.split('      - name: Checkout FacturaScripts\n', 1)[1].split('\n      - name:', 1)[0]
        self.assertIn('ref: ${{ matrix.core_sha }}', checkout)
        self.assertIn('persist-credentials: false', checkout)
        self.assertIn('EXPECTED_CORE_SHA: ${{ matrix.core_sha }}', self.runtime)
        self.assertIn('test "$(git rev-parse HEAD)" = "$EXPECTED_CORE_SHA"', self.runtime)

    def test_native_phpunit_runs_with_real_core_dependencies_on_every_entry(self):
        self.assertIn('composer install --no-interaction --prefer-dist\n', self.runtime)
        self.assertNotIn('composer install --no-interaction --prefer-dist --no-dev', self.runtime)
        self.assertIn('php vendor/bin/phpunit --configuration Plugins/BeplyPDFStudio/phpunit.xml.dist', self.runtime)
        self.assertIn('--testdox --fail-on-risky --fail-on-warning', self.runtime)
        self.assertNotIn('continue-on-error:', self.runtime)
        self.assertNotIn('|| true', self.runtime)
        self.assertLess(self.runtime.index('Prepare plugin runtime'), self.runtime.index('Native PHPUnit against selected Core'))

    def test_source_check_is_part_of_the_light_job(self):
        self.assertIn('python3 .github/ci/test_owned_runner_core_matrix.py', triggers.job_blocks(self.tests)['php84_compat'])

    def test_tests_have_only_the_reserved_untrusted_runner_label(self):
        labels = re.findall(r'^    runs-on: (.+)$', self.tests, flags=re.M)
        self.assertEqual(labels, ['arc-public-studio'] * 3)
        self.assertNotIn('secrets.', self.tests)
        self.assertNotIn('sudo ', self.tests)
        self.assertNotIn('shivammathur/setup-php', self.tests)
        for job in triggers.job_blocks(self.tests).values():
            self.assertEqual(job.count('persist-credentials: false'), job.count('uses: actions/checkout@'))

    def test_release_reserves_a_separate_trusted_route(self):
        labels = re.findall(r'^    runs-on: (.+)$', self.release, flags=re.M)
        self.assertEqual(labels, ['arc-studio-release'] * 2)
        self.assertNotIn('arc-public-studio', self.release)
        self.assertNotIn('pull_request', self.release)

    def test_functional_consumers_remain_in_the_runtime_matrix(self):
        for command in ['Tests/run-http-export-contract.php', 'Tests/run-short-document-pagination.php',
                        'Tests/verify_noto_html_embedded_weights.py', 'Tests/verify_noto_cezpdf_fonts.py',
                        'Tests/run-report-visual.php', 'Tests/run-default-style-seed.php']:
            self.assertIn(command, self.runtime)
        self.assertLess(self.runtime.index('verify_noto_cezpdf_fonts.py'), self.runtime.index('run-report-visual.php'))
        self.assertIn('BEPDF_CI_CORE_DIR="$PWD" BEPDF_CI_SCRATCH="$fonts"', self.runtime)
        self.assertIn("if: always()", self.runtime)


class PreinstalledPhpSelectionTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='studio-ci-contract-', dir=os.environ.get('RUNNER_TEMP'))
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        for name in ('mktemp', 'ln'):
            (self.bin / name).symlink_to('/usr/bin/' + name)
        self.executable('id', '#!/bin/sh\necho "${FAKE_UID:-1001}"\n')
        self.executable('php8.2', '#!/bin/sh\nprintf "8.2\\n" >> "$PHP_CALLS"\nexit "${FAKE_PHP_EXIT:-0}"\n')
        self.executable('php8.4', '#!/bin/sh\nprintf "8.4\\n" >> "$PHP_CALLS"\nexit "${FAKE_PHP_EXIT:-0}"\n')
        self.env = dict(PATH=str(self.bin), RUNNER_TEMP=str(self.root), GITHUB_PATH=str(self.root / 'path'),
                        GITHUB_ENV=str(self.root / 'env'), PHP_CALLS=str(self.root / 'calls'))

    def executable(self, name, body):
        path = self.bin / name
        path.write_text(body)
        path.chmod(0o700)

    def run_selection(self, version, **changes):
        return subprocess.run(['/bin/bash', str(ROOT / '.github/ci/select-preinstalled-php.sh'), version],
                              env={**self.env, **changes}, capture_output=True, text=True, timeout=10)

    def assert_no_published_selection(self):
        self.assertFalse((self.root / 'path').exists())
        self.assertFalse((self.root / 'env').exists())
        self.assertEqual(list(self.root.glob('studio-php.*')), [])

    def test_only_supported_versions_are_executed(self):
        result = self.run_selection('8.3')
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.root / 'calls').exists())
        self.assert_no_published_selection()

    def test_root_is_rejected_before_executing_php(self):
        result = self.run_selection('8.2', FAKE_UID='0')
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.root / 'calls').exists())
        self.assert_no_published_selection()

    def test_missing_preinstalled_php_is_not_installed_or_published(self):
        (self.bin / 'php8.2').unlink()
        result = self.run_selection('8.2')
        self.assertNotEqual(result.returncode, 0)
        self.assert_no_published_selection()

    def test_failed_version_or_extension_probe_does_not_publish_path(self):
        result = self.run_selection('8.2', FAKE_PHP_EXIT='1')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((self.root / 'calls').read_text(), '8.2\n')
        self.assert_no_published_selection()

    def test_each_supported_version_publishes_only_its_preinstalled_binary(self):
        for version in ('8.2', '8.4'):
            with self.subTest(version=version):
                result = self.run_selection(version)
                self.assertEqual(result.returncode, 0, result.stderr)
                directory = Path((self.root / 'path').read_text().splitlines()[-1])
                self.assertEqual((directory / 'php').resolve(), self.bin / ('php' + version))
                self.assertEqual((directory / 'ci.ini').read_text(), 'opcache.jit_buffer_size=0\n')
                self.assertIn('PHP_INI_SCAN_DIR=:' + str(directory), (self.root / 'env').read_text())


if __name__ == '__main__':
    unittest.main()
