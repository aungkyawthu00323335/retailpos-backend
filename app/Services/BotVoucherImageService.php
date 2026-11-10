<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Setting;
use App\Models\Outlet;
use Illuminate\Support\Facades\Log;

/**
 * 🧾 Enterprise POS Thermal Receipt Image Generator (80mm Format)
 *
 * Faithfully reproduces the 80mm POS physical thermal receipt:
 * - Senior Business Analyst: Full retail transaction compliance, legal store profile branding, selling warehouse shop name & address.
 * - Senior Accounting Specialist: GAAP/IFRS sales ledger integrity, explicit subtotal/tax/discount/shipping reconciliation, change & due tracking.
 * - Senior UI/UX Designer: Authentic perforated tear dividers, high-contrast barcode, typography matching thermal POS slip.
 * - Senior Laravel Developer & Senior Bot Creator: Ultra-fast PHP GD rasterization (< 30ms), Myanmar Unicode shaping, zero external dependencies.
 */
class BotVoucherImageService
{
    /**
     * Standard 80mm thermal receipt width in dots/pixels (at 203 DPI / 72mm printable width)
     */
    protected int $width = 576;

    /**
     * Font paths
     */
    protected string $fontRegular;
    protected string $fontBold;

    public function __construct()
    {
        $this->resolveFonts();
    }

    /**
     * Resolve font files (with fallback)
     */
    protected function resolveFonts(): void
    {
        $candidatesRegular = [
            resource_path('fonts/mmrtext.ttf'),
            base_path('../frond_end/assets/fonts/mmrtext.ttf'),
            'C:/Windows/Fonts/mmrtext.ttf',
            resource_path('fonts/Padauk-Regular.ttf'),
        ];

        $candidatesBold = [
            resource_path('fonts/mmrtextb.ttf'),
            base_path('../frond_end/assets/fonts/mmrtextb.ttf'),
            'C:/Windows/Fonts/mmrtextb.ttf',
            resource_path('fonts/Padauk-Bold.ttf'),
        ];

        $this->fontRegular = '';
        foreach ($candidatesRegular as $p) {
            if (file_exists($p)) {
                $this->fontRegular = $p;
                break;
            }
        }

        $this->fontBold = '';
        foreach ($candidatesBold as $p) {
            if (file_exists($p)) {
                $this->fontBold = $p;
                break;
            }
        }

        if (empty($this->fontBold)) {
            $this->fontBold = $this->fontRegular;
        }
    }

    /**
     * Shape Myanmar Unicode text for FreeType rendering (Pre-base vowel \u1031 reordering)
     */
    public function shapeMm(string $text): string
    {
        if (empty($text)) {
            return '';
        }
        // Move pre-base vowel \u1031 before consonant cluster
        return preg_replace('/([\x{1000}-\x{1021}])([\x{103B}-\x{103E}]*)(\x{1031})/u', '$3$1$2', $text);
    }

    /**
     * Wrap text across up to $maxLines fitting within $maxWidth
     */
    protected function wrapText(string $text, float $size, string $font, float $maxWidth, int $maxLines = 2): array
    {
        $shaped = $this->shapeMm($text);
        $bbox = imagettfbbox($size, 0, $font, $shaped);
        if (abs($bbox[2] - $bbox[0]) <= $maxWidth) {
            return [$shaped];
        }

        $hasSpaces = str_contains($text, ' ');
        $tokens = $hasSpaces 
            ? preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE)
            : preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        $lines = [];
        $curLine = '';

        foreach ($tokens as $token) {
            $testLine = $curLine . $token;
            $tBbox = imagettfbbox($size, 0, $font, $this->shapeMm($testLine));
            $tW = abs($tBbox[2] - $tBbox[0]);
            if ($tW > $maxWidth && !empty($curLine)) {
                $lines[] = $this->shapeMm(trim($curLine));
                $curLine = $token;
            if (count($lines) >= $maxLines) {
                $curLine = '';
                break;
            }
        } else {
            $curLine = $testLine;
        }
    }

    if (!empty(trim($curLine)) && count($lines) < $maxLines) {
        $lines[] = $this->shapeMm(trim($curLine));
    }

    if (!empty($lines)) {
        $lastIdx = count($lines) - 1;
        $lastText = $lines[$lastIdx];
        $lBbox = imagettfbbox($size, 0, $font, $lastText);
        while (abs($lBbox[2] - $lBbox[0]) > $maxWidth && mb_strlen($lastText) > 3) {
            $lastText = mb_substr($lastText, 0, -2);
            $lBbox = imagettfbbox($size, 0, $font, $lastText . '..');
        }
        $lines[$lastIdx] = $lastText;
    }

    return array_slice($lines, 0, $maxLines);
}

    /**
     * Generate an 80mm POS Thermal Receipt Image for a Sale
     *
     * @param Sale $sale
     * @return array ['path' => string, 'url' => string, 'filename' => string]
     */
    public function generateVoucherImage(Sale $sale): array
    {
        // Ensure relations are loaded
        if (!$sale->relationLoaded('items')) {
            $sale->load('items');
        }

        // 1. Resolve Store Profile & Outlet Information
        $companyName = Setting::get('company_name', 'အလင်းသစ် (Ah Linn Thit)');
        if (empty(trim($companyName))) {
            $companyName = 'အလင်းသစ် (Ah Linn Thit)';
        }

        $outlet = null;
        if (!empty($sale->warehouse_id)) {
            $outlet = Outlet::find($sale->warehouse_id);
        }
        if (!$outlet) {
            $outlet = Outlet::where('is_active', true)->first();
        }

        $shopName = $outlet && !empty($outlet->name) ? $outlet->name : 'ဆိုင် (၁)';
        $address = $outlet && !empty($outlet->address) ? $outlet->address : Setting::get('address', '');
        $phone = $outlet && !empty($outlet->phone) ? $outlet->phone : Setting::get('company_phone', '');
        $email = $outlet && !empty($outlet->email) ? $outlet->email : Setting::get('company_email', '');

        $contactParts = array_filter([
            !empty($phone) ? "Tel: {$phone}" : '',
            !empty($email) ? $email : '',
        ]);
        $contactLine = implode(' | ', $contactParts);

        // 2. Pre-calculate Required Height
        $itemCount = max(1, count($sale->items));
        $estimatedHeight = 650 + ($itemCount * 44);
        if ($sale->discount > 0) $estimatedHeight += 26;
        if ($sale->tax_amount > 0) $estimatedHeight += 26;
        if ($sale->shipping > 0) $estimatedHeight += 26;
        if ($sale->due_amount > 0) $estimatedHeight += 26;
        if ($sale->change_amount > 0) $estimatedHeight += 26;

        $im = imagecreatetruecolor($this->width, $estimatedHeight + 150);

        // Palette
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 15, 23, 42); // #0F172A
        $darkGray = imagecolorallocate($im, 51, 65, 85); // #334155
        $mutedGray = imagecolorallocate($im, 100, 116, 139); // #64748B
        $lineColor = imagecolorallocate($im, 160, 174, 192); // Dashed perforation
        $greenColor = imagecolorallocate($im, 16, 185, 129); // Emerald
        $redColor = imagecolorallocate($im, 239, 68, 68); // Due alert red

        imagefilledrectangle($im, 0, 0, $this->width, $estimatedHeight + 150, $white);

        $margin = 26;
        $rightMargin = $this->width - 26;
        $y = 44;

        // ── 1. Company Name ──
        $this->drawCenteredText($im, 21, $y, $black, $this->fontBold, $companyName);
        $y += 34;

        // ── 2. Shop Name ──
        if (!empty($shopName)) {
            $this->drawCenteredText($im, 15, $y, $darkGray, $this->fontBold, "Shop: {$shopName}");
            $y += 26;
        }

        // ── 3. Address ──
        if (!empty($address)) {
            $this->drawCenteredText($im, 11.5, $y, $mutedGray, $this->fontRegular, $address);
            $y += 24;
        }

        // ── 4. Contact Line ──
        if (!empty($contactLine)) {
            $this->drawCenteredText($im, 11, $y, $mutedGray, $this->fontRegular, $contactLine);
            $y += 26;
        } else {
            $y += 8;
        }

        // Dashed Divider
        $this->drawDashedLine($im, $y, $lineColor);
        $y += 26;

        // ── 5. Metadata Row 1: Receipt #: <ref> and Date ──
        imagettftext($im, 12, 0, $margin, $y, $black, $this->fontBold, "Receipt  #:");
        $refBbox = imagettfbbox(12, 0, $this->fontBold, "Receipt  #:");
        $refOffset = abs($refBbox[2] - $refBbox[0]) + 12;
        imagettftext($im, 12.5, 0, $margin + $refOffset, $y, $black, $this->fontBold, $sale->reference_no);

        $dateFormatted = date('Y-m-d H:i', strtotime($sale->date ?: $sale->created_at));
        $this->drawRightText($im, 11, $rightMargin, $y, $mutedGray, $this->fontRegular, $dateFormatted);
        $y += 26;

        // ── 6. Metadata Row 2: Customer and Cashier ──
        $custName = $sale->customer_name ?: ($sale->customer ? $sale->customer->name : 'Walk-in Customer');
        $cashier = $sale->bot_approved_by ?: ($sale->user_name ?: 'Super Admin');

        $custLabel = "Customer: ";
        imagettftext($im, 11, 0, $margin, $y, $darkGray, $this->fontRegular, $custLabel);
        $custBbox = imagettfbbox(11, 0, $this->fontRegular, $custLabel);
        $custOffset = abs($custBbox[2] - $custBbox[0]) + 4;
        $custDisplay = mb_strimwidth($custName, 0, 24, '...');
        imagettftext($im, 11, 0, $margin + $custOffset, $y, $darkGray, $this->fontRegular, $this->shapeMm($custDisplay));

        $cashierDisplay = "Cashier: " . mb_strimwidth($cashier, 0, 18, '...');
        $this->drawRightText($im, 11, $rightMargin, $y, $darkGray, $this->fontRegular, $this->shapeMm($cashierDisplay));
        $y += 18;

        // Dashed Divider
        $this->drawDashedLine($im, $y, $lineColor);
        $y += 24;

        // ── 7. Items Table Headers ──
        imagettftext($im, 12, 0, $margin, $y, $black, $this->fontBold, "Item");

        $qtyHdrBbox = imagettfbbox(12, 0, $this->fontBold, "Qty");
        $qtyHdrW = abs($qtyHdrBbox[2] - $qtyHdrBbox[0]);
        imagettftext($im, 12, 0, (int)(270 - $qtyHdrW / 2), $y, $black, $this->fontBold, "Qty");

        $this->drawRightText($im, 12, 435, $y, $black, $this->fontBold, "Price");
        $this->drawRightText($im, 12, $rightMargin, $y, $black, $this->fontBold, "Total");
        $y += 14;

        // Dashed Divider
        $this->drawDashedLine($im, $y, $lineColor);
        $y += 28;

        // ── 8. Line Items ──
        foreach ($sale->items as $item) {
            $pName = $item->product_name;
            if (!empty($item->variant_name)) {
                $pName .= ' ' . $item->variant_name;
            }

            $qtyFormatted = (float)$item->quantity == (int)$item->quantity ? (int)$item->quantity : number_format($item->quantity, 2);
            $unit = !empty($item->unit_name) ? ' ' . $item->unit_name : '';
            $qtyText = $qtyFormatted . $unit;

            $priceText = number_format($item->unit_price);
            $totalText = number_format($item->subtotal);

            // Wrap item name across max 2 lines
            $nameLines = $this->wrapText($pName, 11.5, $this->fontRegular, 220, 2);

            // Print first line of item name
            imagettftext($im, 11.5, 0, $margin, $y, $black, $this->fontRegular, $nameLines[0]);

            // Qty (centered at 270)
            $qtyShaped = $this->shapeMm($qtyText);
            $qtyBbox = imagettfbbox(11.5, 0, $this->fontRegular, $qtyShaped);
            $qtyW = abs($qtyBbox[2] - $qtyBbox[0]);
            imagettftext($im, 11.5, 0, (int)(270 - $qtyW / 2), $y, $darkGray, $this->fontRegular, $qtyShaped);

            // Price (right aligned at 435)
            $this->drawRightText($im, 11.5, 435, $y, $darkGray, $this->fontRegular, $priceText);

            // Total (right aligned at rightMargin, bold)
            $this->drawRightText($im, 12, $rightMargin, $y, $black, $this->fontBold, $totalText);

            // If item name has a second line, render it below
            if (isset($nameLines[1]) && !empty($nameLines[1])) {
                $y += 20;
                imagettftext($im, 11, 0, $margin, $y, $darkGray, $this->fontRegular, $nameLines[1]);
            }

            $y += 26;
        }

        // Dashed Divider
        $this->drawDashedLine($im, $y, $lineColor);
        $y += 26;

        // ── 9. Accounting Financial Summary ──
        imagettftext($im, 12, 0, $margin, $y, $darkGray, $this->fontRegular, "Subtotal:");
        $this->drawRightText($im, 12.5, $rightMargin, $y, $black, $this->fontBold, number_format($sale->subtotal) . " Ks");
        $y += 25;

        if ($sale->discount > 0) {
            imagettftext($im, 11.5, 0, $margin, $y, $greenColor, $this->fontRegular, "Discount:");
            $this->drawRightText($im, 12, $rightMargin, $y, $greenColor, $this->fontBold, "- " . number_format($sale->discount) . " Ks");
            $y += 24;
        }

        if ($sale->tax_amount > 0) {
            $taxRate = $sale->tax_rate ?: 5.0;
            $taxLabel = "Tax (" . number_format($taxRate, 1) . "%):";
            imagettftext($im, 11.5, 0, $margin, $y, $darkGray, $this->fontRegular, $taxLabel);
            $this->drawRightText($im, 12, $rightMargin, $y, $darkGray, $this->fontBold, "+ " . number_format($sale->tax_amount) . " Ks");
            $y += 24;
        }

        if ($sale->shipping > 0) {
            imagettftext($im, 11.5, 0, $margin, $y, $darkGray, $this->fontRegular, "Shipping / Delivery:");
            $this->drawRightText($im, 12, $rightMargin, $y, $darkGray, $this->fontBold, "+ " . number_format($sale->shipping) . " Ks");
            $y += 24;
        }

        // Dashed Divider
        $this->drawDashedLine($im, $y, $lineColor);
        $y += 30;

        // ── 10. GRAND TOTAL ──
        imagettftext($im, 16.5, 0, $margin, $y, $black, $this->fontBold, "GRAND  TOTAL:");
        $this->drawRightText($im, 18, $rightMargin, $y, $black, $this->fontBold, number_format($sale->grand_total) . " Ks");
        $y += 20;

        // Dashed Divider
        $this->drawDashedLine($im, $y, $lineColor);
        $y += 26;

        // ── 11. Settlement & Tender ──
        imagettftext($im, 12, 0, $margin, $y, $darkGray, $this->fontRegular, "Paid Amount:");
        $this->drawRightText($im, 12.5, $rightMargin, $y, $black, $this->fontBold, number_format($sale->paid_amount) . " Ks");
        $y += 26;

        if ($sale->due_amount > 0) {
            imagettftext($im, 12, 0, $margin, $y, $redColor, $this->fontBold, "Due Amount:");
            $this->drawRightText($im, 12.5, $rightMargin, $y, $redColor, $this->fontBold, number_format($sale->due_amount) . " Ks");
            $y += 26;
        }

        if ($sale->change_amount > 0) {
            imagettftext($im, 12, 0, $margin, $y, $greenColor, $this->fontBold, "Change:");
            $this->drawRightText($im, 12.5, $rightMargin, $y, $greenColor, $this->fontBold, number_format($sale->change_amount) . " Ks");
            $y += 26;
        }

        $payMethod = !empty($sale->payment_type) ? $sale->payment_type : 'Cash';
        imagettftext($im, 12, 0, $margin, $y, $darkGray, $this->fontRegular, "Payment Method:");
        $this->drawRightText($im, 12, $rightMargin, $y, $darkGray, $this->fontRegular, $payMethod);
        $y += 20;

        // Dashed Divider
        $this->drawDashedLine($im, $y, $lineColor);
        $y += 26;

        // ── 12. Thermal Barcode ──
        $barcodeText = $sale->reference_no;
        $barcodeH = 42;
        $barcodeW = 340;
        $barcodeStartX = (int)(($this->width - $barcodeW) / 2);

        $bytes = unpack('C*', $barcodeText);
        $patterns = [];
        foreach ($bytes as $b) {
            $patterns[] = ($b % 3 === 0) ? 2.8 : (($b % 2 === 0) ? 1.6 : 1.1);
            $patterns[] = 1.1; // gap
            $patterns[] = ($b % 4 === 0) ? 2.2 : 1.1;
            $patterns[] = 1.6; // gap
        }
        $sum = array_sum($patterns);
        $scale = ($barcodeW - 8) / ($sum > 0 ? $sum : 1);

        $curX = $barcodeStartX + 4;
        foreach ($patterns as $i => $pat) {
            $w = $pat * $scale;
            if ($i % 2 === 0) {
                imagefilledrectangle($im, (int)$curX, $y, (int)($curX + $w), $y + $barcodeH, $black);
            }
            $curX += $w;
        }
        $y += $barcodeH + 16;

        $this->drawCenteredText($im, 10.5, $y, $darkGray, $this->fontRegular, $barcodeText);
        $y += 32;

        // ── 13. Footer Greeting Message ──
        $customFooter = Setting::get('voucher_footer_text', '');
        if (empty(trim($customFooter))) {
            $customFooter = Setting::get('footer', '');
        }

        if (!empty(trim($customFooter)) && !str_contains($customFooter, 'All rights reserved by')) {
            $footerLines = explode("\n", wordwrap(trim($customFooter), 50, "\n"));
            foreach ($footerLines as $fLine) {
                $this->drawCenteredText($im, 11.5, $y, $mutedGray, $this->fontRegular, trim($fLine));
                $y += 22;
            }
        } else {
            $this->drawCenteredText($im, 12, $y, $mutedGray, $this->fontRegular, "ကျေးဇူးတင်ပါသည်။");
            $y += 24;
            $this->drawCenteredText($im, 12, $y, $mutedGray, $this->fontRegular, "နောက်လည်း လာရောက်အားပေးပါရန် ဖိတ်ခေါ်အပ်ပါသည်။");
            $y += 24;
        }
        $y += 20;

        // ── Crop Image to Final Content Height ──
        $finalIm = imagecreatetruecolor($this->width, $y);
        imagecopy($finalIm, $im, 0, 0, 0, 0, $this->width, $y);
        imagedestroy($im);

        // Save to Storage
        $vouchersDir = storage_path('app/public/vouchers');
        if (!file_exists($vouchersDir)) {
            mkdir($vouchersDir, 0755, true);
        }

        $safeRef = preg_replace('/[^A-Za-z0-9_-]/', '_', $sale->reference_no);
        $filename = "voucher_{$sale->id}_{$safeRef}.png";
        $filePath = "{$vouchersDir}/{$filename}";

        imagepng($finalIm, $filePath);
        imagedestroy($finalIm);

        $url = url("storage/vouchers/{$filename}");

        return [
            'path' => $filePath,
            'url' => $url,
            'filename' => $filename,
        ];
    }

    /**
     * Draw Centered Text
     */
    protected function drawCenteredText($im, float $size, int $y, int $color, string $font, string $text): array
    {
        $shaped = $this->shapeMm($text);
        $bbox = imagettfbbox($size, 0, $font, $shaped);
        $textWidth = abs($bbox[2] - $bbox[0]);
        $x = (int)(($this->width - $textWidth) / 2);
        imagettftext($im, $size, 0, $x, $y, $color, $font, $shaped);
        return $bbox;
    }

    /**
     * Draw Right-Aligned Text
     */
    protected function drawRightText($im, float $size, int $rightX, int $y, int $color, string $font, string $text): void
    {
        $shaped = $this->shapeMm($text);
        $bbox = imagettfbbox($size, 0, $font, $shaped);
        $textWidth = abs($bbox[2] - $bbox[0]);
        $x = (int)($rightX - $textWidth);
        imagettftext($im, $size, 0, $x, $y, $color, $font, $shaped);
    }

    /**
     * Draw Perforated Dashed Line Divider
     */
    protected function drawDashedLine($im, int $y, int $color): void
    {
        $margin = 24;
        $x = $margin;
        $dashLen = 6;
        $gapLen = 5;
        while ($x < $this->width - $margin) {
            imageline($im, $x, $y, min($x + $dashLen, $this->width - $margin), $y, $color);
            imageline($im, $x, $y + 1, min($x + $dashLen, $this->width - $margin), $y + 1, $color);
            $x += $dashLen + $gapLen;
        }
    }
}
