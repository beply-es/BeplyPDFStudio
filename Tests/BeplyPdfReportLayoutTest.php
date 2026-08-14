<?php

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\BeplyPDFStudio;

use FacturaScripts\Plugins\BeplyPDFStudio\Lib\Templates\AbstractBeplyPdfLayout;
use PHPUnit\Framework\TestCase;

final class BeplyPdfReportLayoutTest extends TestCase
{
    public function testEveryTemplateOwnsItsCompactReportProfile(): void
    {
        $profiles = [];
        foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
            $profile = $layout->reportLayout();

            $this->assertSame($key, $profile->key);
            $this->assertNotSame('generic', $profile->header);
            $this->assertNotSame('generic', $profile->table);
            $this->assertGreaterThan(0.0, $profile->fontScale);
            $this->assertLessThanOrEqual(1.0, $profile->fontScale);
            $this->assertGreaterThan(0.0, $profile->titleScale);
            $this->assertLessThanOrEqual(1.0, $profile->titleScale);
            $this->assertLessThanOrEqual(4.5, $profile->rowGap);

            $profiles[] = $profile->key . ':' . $profile->header . ':' . $profile->table;
        }

        $this->assertCount(9, $profiles);
        $this->assertCount(9, array_unique($profiles));
    }

    public function testExpectedReportIdentityIsDeclaredByEachTemplate(): void
    {
        $expected = [
            'legacy_standard' => ['classic', 'classic'],
            'legacy_summary' => ['summary', 'summary'],
            'legacy_boxes' => ['boxes', 'boxes'],
            'legacy_framed' => ['framed', 'framed'],
            'legacy_banner' => ['banner', 'banner'],
            'corporate' => ['corporate', 'corporate'],
            'azure' => ['modern', 'modern'],
            'prisma' => ['prisma', 'prisma'],
            'studio_quote' => ['studio', 'studio'],
        ];

        foreach (AbstractBeplyPdfLayout::registry() as $key => $layout) {
            $profile = $layout->reportLayout();
            $this->assertSame($expected[$key][0], $profile->header, $key . ' header');
            $this->assertSame($expected[$key][1], $profile->table, $key . ' table');
        }
    }
}
