<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\BeplyPDFStudio\Lib\Document;

/** Derives corporate Cezpdf header coordinates from the rendered metadata rows. */
final class BeplyPdfCorporateHeaderGeometry
{
    private const META_TOP_OFFSET = 27.0;
    private const DEFAULT_RULE_OFFSET = 72.0;
    private const PARTIES_GAP = 12.0;
    private const META_CLEARANCE = 12.0;

    /**
     * @return array{
     *   band_bottom: float,
     *   meta_top: float,
     *   meta_row_step: float,
     *   last_meta_baseline: float,
     *   rule_y: float,
     *   parties_top: float
     * }
     */
    public static function resolve(float $bandBottom, float $fontSize, int $metadataRows): array
    {
        $metaTop = $bandBottom - self::META_TOP_OFFSET;
        $rowStep = $fontSize + 6.0;
        $lastMetaBaseline = $metaTop - (max(0, $metadataRows - 1) * $rowStep);
        $defaultRuleY = $bandBottom - self::DEFAULT_RULE_OFFSET;
        $ruleY = min($defaultRuleY, $lastMetaBaseline - self::META_CLEARANCE);

        return [
            'band_bottom' => $bandBottom,
            'meta_top' => $metaTop,
            'meta_row_step' => $rowStep,
            'last_meta_baseline' => $lastMetaBaseline,
            'rule_y' => $ruleY,
            'parties_top' => $ruleY - self::PARTIES_GAP,
        ];
    }
}
