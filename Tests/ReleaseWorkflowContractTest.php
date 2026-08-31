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

    public function testDevUploadUsesTechnicalFsNameAndLifecycleStableWitness(): void
    {
        $root = dirname(__DIR__);
        $workflow = $this->workflow();
        $helper = (string) file_get_contents($root . '/scripts/ci/upload_catalog_release.sh');

        $this->assertTrue(str_contains($workflow, 'PLUGIN_FS_NAME: ${{ steps.ini.outputs.name }}'));
        $this->assertTrue(str_contains($workflow, 'PLUGIN_SLUG: beplypdfstudio'));
        $this->assertTrue(str_contains($workflow, 'RELEASE_TRACK: main'));
        $this->assertTrue(str_contains($workflow, 'bash scripts/ci/upload_catalog_release.sh'));
        $this->assertTrue(str_contains($helper, '/api/v1/plugins/release-witness'));
        $this->assertTrue(str_contains($helper, '.data.pluginFsName'));
        $this->assertFalse(str_contains($helper, '.data.pluginName'));
        $this->assertTrue(str_contains($helper, 'VERSION_ARTIFACT_IMMUTABLE'));
        $this->assertTrue(str_contains($helper, 'VERSION_ALREADY_APPROVED'));
        $this->assertFalse(str_contains($helper, 'SUBMISSION_PENDING'));
        $this->assertFalse(str_contains($workflow, '/api/v1/plugins/pending-releases'));
    }

    public function testDevWitnessMatchesEverySentProvenanceField(): void
    {
        $root = dirname(__DIR__);
        $helper = (string) file_get_contents($root . '/scripts/ci/upload_catalog_release.sh');
        $verifier = (string) file_get_contents($root . '/scripts/ci/verify_release_witness.py');

        foreach ([
            'sourceRepoFullName',
            'sourceBranch',
            'releaseTrack',
            'sourceReleaseTag',
            'sourceReleaseUrl',
            'sourcePublishedAt',
        ] as $field) {
            $this->assertTrue(str_contains($helper, $field), $field);
        }
        foreach ([
            'pluginId',
            'versionId',
            'pluginSlug',
            'pluginFsName',
            'version',
            'releaseTrack',
            'releaseStatus',
            'sourceRepoFullName',
            'sourceBranch',
            'sourceReleaseTag',
            'sourceReleaseUrl',
            'sourcePublishedAt',
            'checksum',
            'fileSize',
        ] as $field) {
            $this->assertTrue(str_contains($verifier, $field), $field);
        }
    }

    public function testProvenanceAndCatalogHelpersHaveFocusedTests(): void
    {
        $root = dirname(__DIR__);

        foreach ([
            'download_provenance_release_asset.py',
            'test_download_provenance_release_asset.py',
            'upload_catalog_release.sh',
            'test_upload_catalog_release.py',
            'verify_release_witness.py',
            'test_verify_release_witness.py',
        ] as $file) {
            $this->assertTrue(is_file($root . '/scripts/ci/' . $file), $file);
        }
        $testsWorkflow = (string) file_get_contents($root . '/.github/workflows/tests.yml');
        $this->assertTrue(str_contains(
            $testsWorkflow,
            'python3 -m unittest scripts.ci.test_download_provenance_release_asset'
        ));
        $this->assertTrue(str_contains(
            $testsWorkflow,
            'python3 -m unittest scripts.ci.test_verify_release_witness scripts.ci.test_upload_catalog_release'
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
