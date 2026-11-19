<?php

namespace App\Support;

/**
 * Single Source of Truth for Stock Adjustment Reason Codes and GAAP P&L Classification.
 */
final class AdjustmentReasonCatalog
{
    public const REASONS = [
        [
            'code' => 'Damaged',
            'label_en' => 'Damaged / Breakage',
            'label_my' => 'ပစ္စည်းပျက်စီးဆုံးရှုံးမှု',
            'direction' => 'Subtraction',
            'accounting_category' => 'inventory_shrinkage_loss',
            'affects_pnl' => true,
            'pnl_section' => 'operating_loss',
        ],
        [
            'code' => 'Expired',
            'label_en' => 'Expired / Spoilage',
            'label_my' => 'သက်တမ်းကုန်ဆုံး/ပုပ်သိုးမှု',
            'direction' => 'Subtraction',
            'accounting_category' => 'inventory_expiry_loss',
            'affects_pnl' => true,
            'pnl_section' => 'operating_loss',
        ],
        [
            'code' => 'Theft / Missing',
            'label_en' => 'Theft / Missing / Shrinkage',
            'label_my' => 'ပျောက်ဆုံး/ခိုးယူခံရမှု',
            'direction' => 'Subtraction',
            'accounting_category' => 'inventory_shrinkage_loss',
            'affects_pnl' => true,
            'pnl_section' => 'operating_loss',
        ],
        [
            'code' => 'Stocktake Deficit',
            'label_en' => 'Stocktake Deficit',
            'label_my' => 'စာရင်းစစ် လျော့နည်းမှု',
            'direction' => 'Subtraction',
            'accounting_category' => 'cycle_count_variance',
            'affects_pnl' => true,
            'pnl_section' => 'operating_loss',
        ],
        [
            'code' => 'Internal Use / Sample',
            'label_en' => 'Internal Use / Sample / Promo',
            'label_my' => 'ဆိုင်တွင်းသုံး/နမူနာပေးမှု',
            'direction' => 'Subtraction',
            'accounting_category' => 'internal_consumption',
            'affects_pnl' => true,
            'pnl_section' => 'operating_loss',
        ],
        [
            'code' => 'Stocktake Surplus',
            'label_en' => 'Stocktake Surplus',
            'label_my' => 'စာရင်းစစ် ပိုလျှံမှု',
            'direction' => 'Addition',
            'accounting_category' => 'cycle_count_variance',
            'affects_pnl' => true,
            'pnl_section' => 'operating_gain',
        ],
        [
            'code' => 'Found / Unrecorded Return',
            'label_en' => 'Found Stock / Unrecorded Recovery',
            'label_my' => 'ပိုလျှံတွေ့ရှိမှု',
            'direction' => 'Addition',
            'accounting_category' => 'inventory_recovery_gain',
            'affects_pnl' => true,
            'pnl_section' => 'operating_gain',
        ],
        [
            'code' => 'Opening Stock',
            'label_en' => 'Opening Stock (Non-P&L Equity)',
            'label_my' => 'အဖွင့်စတော့ (အမြတ်အရှုံးစာရင်း မဝင်)',
            'direction' => 'Addition',
            'accounting_category' => 'equity_opening_stock',
            'affects_pnl' => false,
            'pnl_section' => 'non_pnl_capital',
        ],
        [
            'code' => 'Capital Contribution',
            'label_en' => 'Capital Stock Injection (Non-P&L)',
            'label_my' => 'အရင်းအနှီး စတော့ထည့်သွင်းမှု (Non-P&L)',
            'direction' => 'Addition',
            'accounting_category' => 'equity_capital_in',
            'affects_pnl' => false,
            'pnl_section' => 'non_pnl_capital',
        ],
        [
            'code' => 'Cost / System Correction',
            'label_en' => 'Data / Valuation Correction (Non-P&L)',
            'label_my' => 'စာရင်း/တန်ဖိုး မှားယွင်းမှု ပြင်ဆင်ချက် (Non-P&L)',
            'direction' => 'Mixed',
            'accounting_category' => 'neutral_correction',
            'affects_pnl' => false,
            'pnl_section' => 'non_pnl_neutral',
        ],
        [
            'code' => 'Audit Correction',
            'label_en' => 'Audit Correction (P&L Variance)',
            'label_my' => 'စာရင်းစစ် ညှိနှိုင်းချက်',
            'direction' => 'Mixed',
            'accounting_category' => 'cycle_count_variance',
            'affects_pnl' => true,
            'pnl_section' => 'operating_variance',
        ],
        [
            'code' => 'Normal / Other',
            'label_en' => 'Normal / Other Operating Adjustment',
            'label_my' => 'သာမန် / အခြား ညှိနှိုင်းချက်',
            'direction' => 'Mixed',
            'accounting_category' => 'general_adjustment',
            'affects_pnl' => true,
            'pnl_section' => 'operating_variance',
        ],
    ];

    private const ALIASES = [
        '' => 'Normal / Other',
        'normal' => 'Normal / Other',
        'normal / other' => 'Normal / Other',
        'other' => 'Normal / Other',
        'damage' => 'Damaged',
        'damaged' => 'Damaged',
        'breakage' => 'Damaged',
        'expiry' => 'Expired',
        'expired' => 'Expired',
        'spoilage' => 'Expired',
        'theft' => 'Theft / Missing',
        'missing' => 'Theft / Missing',
        'shrinkage' => 'Theft / Missing',
        'theft / missing' => 'Theft / Missing',
        'stocktake deficit' => 'Stocktake Deficit',
        'internal use' => 'Internal Use / Sample',
        'internal use / sample' => 'Internal Use / Sample',
        'sample' => 'Internal Use / Sample',
        'stocktake surplus' => 'Stocktake Surplus',
        'found' => 'Found / Unrecorded Return',
        'found / unrecorded return' => 'Found / Unrecorded Return',
        'opening stock' => 'Opening Stock',
        'opening balance' => 'Opening Stock',
        'initial stock' => 'Opening Stock',
        'capital contribution' => 'Capital Contribution',
        'capital stock' => 'Capital Contribution',
        'cost / system correction' => 'Cost / System Correction',
        'system correction' => 'Cost / System Correction',
        'valuation correction' => 'Cost / System Correction',
        'cost correction' => 'Cost / System Correction',
        'non-operating' => 'Cost / System Correction',
        'audit correction' => 'Audit Correction',
    ];

    public static function all(): array
    {
        return self::REASONS;
    }

    public static function normalize(?string $reasonCode): string
    {
        $trimmed = trim((string)($reasonCode ?? ''));
        if ($trimmed === '') {
            return 'Normal / Other';
        }
        $lower = strtolower($trimmed);
        if (isset(self::ALIASES[$lower])) {
            return self::ALIASES[$lower];
        }
        return substr($trimmed, 0, 100);
    }

    public static function classify(?string $reasonCode, ?string $type = null, ?bool $explicitAffectsPnl = null): array
    {
        $normalized = self::normalize($reasonCode);
        $matched = null;
        foreach (self::REASONS as $entry) {
            if (strtolower($entry['code']) === strtolower($normalized)) {
                $matched = $entry;
                break;
            }
        }

        $category = $matched['accounting_category'] ?? 'general_adjustment';
        $defaultAffectsPnl = $matched['affects_pnl'] ?? !self::isNonPnlReasonCode($normalized);
        $affectsPnl = $explicitAffectsPnl !== null ? $explicitAffectsPnl : $defaultAffectsPnl;

        if (!$affectsPnl && $category === 'general_adjustment') {
            $category = strtolower(trim((string)$type)) === 'addition'
                ? 'equity_opening_stock'
                : 'neutral_correction';
        } elseif ($affectsPnl && in_array($category, ['equity_opening_stock', 'equity_capital_in', 'neutral_correction'], true)) {
            $category = 'general_adjustment';
        }

        return [
            'reason_code' => $normalized,
            'accounting_category' => $category,
            'affects_pnl' => $affectsPnl,
            'pnl_section' => $affectsPnl
                ? ($matched['pnl_section'] ?? 'operating_variance')
                : 'non_pnl_capital',
        ];
    }

    public static function isNonPnlReasonCode(?string $reasonCode): bool
    {
        $lower = strtolower(trim((string)($reasonCode ?? '')));
        return in_array($lower, self::nonPnlReasonCodesLower(), true);
    }

    /**
     * Lowercase reason codes that must ALWAYS be excluded from Operating P&L unless overridden.
     */
    public static function nonPnlReasonCodesLower(): array
    {
        return [
            'opening stock',
            'opening balance',
            'initial stock',
            'capital contribution',
            'capital stock',
            'cost / system correction',
            'system correction',
            'valuation correction',
            'cost correction',
            'non-operating',
        ];
    }
}
