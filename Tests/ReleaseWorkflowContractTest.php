<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowContractTest extends TestCase
{
    private function workflow(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/release.yml');
    }

    public function testMainPublishesOnlyADevCandidate(): void
    {
        $workflow = $this->workflow();

        $this->assertTrue(str_contains($workflow, "if: github.ref == 'refs/heads/main'"));
        $this->assertTrue(str_contains($workflow, 'Upload candidate to Beply dev'));
        $this->assertTrue(str_contains($workflow, 'actions/upload-artifact@bbbca2ddaa5d8feaa63e36b76fdaad77386f024f'));
        $this->assertTrue(str_contains($workflow, "if: github.ref_type == 'tag'"));
    }

    public function testMainPersistsAndReusesOneImmutableProvenanceZip(): void
    {
        $workflow = $this->workflow();

        $this->assertSame(1, substr_count($workflow, '- name: Build plugin zip'));
        $this->assertTrue(str_contains($workflow, 'Publish dev provenance release'));
        $this->assertTrue(str_contains($workflow, 'tag_name: "main-${{ github.sha }}"'));
        $this->assertTrue(str_contains($workflow, 'prerelease: true'));
        $this->assertTrue(str_contains($workflow, 'overwrite_files: false'));
        $this->assertTrue(str_contains($workflow, 'download_provenance_release_asset.py'));
        $this->assertTrue(str_contains($workflow, '--source-tag "main-${GITHUB_SHA}"'));
    }

    public function testMissingDevTokenFailsClosed(): void
    {
        $workflow = $this->workflow();

        $this->assertTrue(str_contains($workflow, 'BEPLY_DEV_CI_TOKEN must be configured for dev platform upload'));
        $this->assertSame(1, preg_match(
            '/if \[ -z "\$BEPLY_DEV_CI_TOKEN" \]; then.*?::error::BEPLY_DEV_CI_TOKEN.*?exit 1/s',
            $workflow
        ));
        $this->assertFalse(str_contains($workflow, 'Candidate remains available as a workflow artifact'));
        $this->assertFalse(str_contains($workflow, 'Skipping dev platform upload'));
    }

    public function testDevUploadRequiresExactPendingReleaseReadback(): void
    {
        $workflow = $this->workflow();

        $this->assertTrue(str_contains($workflow, '/api/v1/plugins/pending-releases'));
        $this->assertTrue(str_contains($workflow, '.sourceRepoFullName'));
        $this->assertTrue(str_contains($workflow, '.sourceReleaseTag'));
        $this->assertTrue(str_contains($workflow, '.pluginName'));
        $this->assertTrue(str_contains($workflow, '.version'));
        $this->assertTrue(str_contains($workflow, '.checksum'));
        $this->assertTrue(str_contains($workflow, '.fileSize'));
        $this->assertTrue(str_contains($workflow, '--arg fs_name "$PLUGIN_NAME"'));
        $this->assertTrue(str_contains($workflow, '.code == "SUBMISSION_PENDING"'));
        $this->assertTrue(str_contains($workflow, 'if [ "$MATCHES" != "1" ]; then'));
        $this->assertTrue(str_contains($workflow, 'expected exactly 1 pending row'));
    }

    public function testProvenanceDownloaderHasFocusedTests(): void
    {
        $root = dirname(__DIR__);

        $this->assertTrue(is_file($root . '/scripts/ci/download_provenance_release_asset.py'));
        $this->assertTrue(is_file($root . '/scripts/ci/test_download_provenance_release_asset.py'));
        $testsWorkflow = (string) file_get_contents($root . '/.github/workflows/tests.yml');
        $this->assertTrue(str_contains(
            $testsWorkflow,
            'python3 -m unittest scripts.ci.test_download_provenance_release_asset'
        ));
    }

    public function testProductionPublicationRequiresAnExplicitMatchingTag(): void
    {
        $workflow = $this->workflow();

        $this->assertTrue(str_contains($workflow, "if: github.ref_type == 'tag'"));
        $this->assertTrue(str_contains($workflow, 'must be main or the exact tag v${PLUGIN_VERSION}'));
        $this->assertTrue(str_contains($workflow, 'Upload candidate to Beply production'));
    }

    public function testReleaseWaitsForTheFullTestWorkflow(): void
    {
        $workflow = $this->workflow();

        $this->assertTrue(str_contains($workflow, 'needs: verify_tests'));
        $this->assertTrue(str_contains($workflow, 'Tests workflow status='));
    }
}
