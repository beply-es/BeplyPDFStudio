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

        $this->assertTrue(str_contains($workflow, "if: github.ref_type == 'branch'"));
        $this->assertTrue(str_contains($workflow, 'Upload candidate to Beply dev'));
        $this->assertTrue(str_contains($workflow, 'actions/upload-artifact@bbbca2ddaa5d8feaa63e36b76fdaad77386f024f'));
    }

    public function testProductionPublicationRequiresAnExplicitMatchingTag(): void
    {
        $workflow = $this->workflow();

        $this->assertTrue(str_contains($workflow, "if: github.ref_type == 'tag'"));
        $this->assertTrue(str_contains($workflow, 'Tag ${GITHUB_REF_NAME} does not match facturascripts.ini version'));
        $this->assertTrue(str_contains($workflow, 'Upload candidate to Beply production'));
    }

    public function testReleaseWaitsForTheFullTestWorkflow(): void
    {
        $workflow = $this->workflow();

        $this->assertTrue(str_contains($workflow, 'needs: verify_tests'));
        $this->assertTrue(str_contains($workflow, 'Tests workflow status='));
    }
}
