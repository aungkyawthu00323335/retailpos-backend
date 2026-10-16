<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PerceptualHashService
{
    private static ?array $bitCountLookup = null;

    /**
     * Compute standard SHA-256 binary hash
     */
    public function computeSha256(string $data): string
    {
        return hash('sha256', $data);
    }

    /**
     * Compute 64-bit DCT-based Perceptual Hash (pHash) as a 16-character hex string.
     * Resistant to compression artifacts, aspect ratio preserved scaling, and noise.
     */
    public function computePhash(string $imageData): ?string
    {
        if (empty($imageData) || !extension_loaded('gd')) {
            return null;
        }

        try {
            $src = @imagecreatefromstring($imageData);
            if (!$src) {
                return null;
            }

            // 1. Resample to 32x32 grayscale
            $size = 32;
            $scaled = imagecreatetruecolor($size, $size);
            imagecopyresampled($scaled, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
            imagefilter($scaled, IMG_FILTER_GRAYSCALE);

            // 2. Extract luminance matrix
            $matrix = [];
            for ($y = 0; $y < $size; $y++) {
                for ($x = 0; $x < $size; $x++) {
                    $rgb = imagecolorat($scaled, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $matrix[$y][$x] = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                }
            }
            imagedestroy($scaled);
            imagedestroy($src);

            // 3. Compute 2D DCT for lowest 8x8 frequencies
            $dct = [];
            $c0 = 1.0 / sqrt(2);
            $pi = M_PI;
            for ($u = 0; $u < 8; $u++) {
                for ($v = 0; $v < 8; $v++) {
                    $sum = 0.0;
                    for ($x = 0; $x < $size; $x++) {
                        for ($y = 0; $y < $size; $y++) {
                            $sum += $matrix[$y][$x] * cos((2 * $x + 1) * $u * $pi / 64) * cos((2 * $y + 1) * $v * $pi / 64);
                        }
                    }
                    $cu = ($u === 0) ? $c0 : 1.0;
                    $cv = ($v === 0) ? $c0 : 1.0;
                    $dct[$u][$v] = 0.25 * $cu * $cv * $sum;
                }
            }

            // 4. Calculate median (excluding DC component at [0][0] for brightness invariance)
            $vals = [];
            for ($u = 0; $u < 8; $u++) {
                for ($v = 0; $v < 8; $v++) {
                    if ($u === 0 && $v === 0) continue;
                    $vals[] = $dct[$u][$v];
                }
            }
            sort($vals);
            $count = count($vals);
            $median = ($vals[intdiv($count, 2)] + $vals[intdiv($count - 1, 2)]) / 2.0;

            // 5. Construct 64-bit binary string
            $bits = '';
            for ($u = 0; $u < 8; $u++) {
                for ($v = 0; $v < 8; $v++) {
                    $bits .= ($dct[$u][$v] >= $median) ? '1' : '0';
                }
            }

            // 6. Convert to 16 hex chars
            $hex = '';
            for ($i = 0; $i < 64; $i += 4) {
                $hex .= dechex(bindec(substr($bits, $i, 4)));
            }
            return str_pad($hex, 16, '0', STR_PAD_LEFT);
        } catch (\Throwable $e) {
            Log::warning('computePhash error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Compute 64-bit Difference Hash (dHash) as a fast fallback
     */
    public function computeDifferenceHash(string $imageData): ?string
    {
        if (empty($imageData) || !extension_loaded('gd')) {
            return null;
        }

        try {
            $src = @imagecreatefromstring($imageData);
            if (!$src) {
                return null;
            }

            $small = imagecreatetruecolor(9, 8);
            imagecopyresampled($small, $src, 0, 0, 0, 0, 9, 8, imagesx($src), imagesy($src));
            imagefilter($small, IMG_FILTER_GRAYSCALE);

            $bits = '';
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $left = imagecolorat($small, $x, $y) & 0xFF;
                    $right = imagecolorat($small, $x + 1, $y) & 0xFF;
                    $bits .= ($left > $right) ? '1' : '0';
                }
            }
            imagedestroy($small);
            imagedestroy($src);

            $hex = '';
            for ($i = 0; $i < 64; $i += 4) {
                $hex .= dechex(bindec(substr($bits, $i, 4)));
            }
            return str_pad($hex, 16, '0', STR_PAD_LEFT);
        } catch (\Throwable $e) {
            Log::warning('computeDifferenceHash error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Calculate Hamming distance between two hex hashes (0 to 64)
     */
    public static function hammingDistance(string $hex1, string $hex2): int
    {
        if (empty($hex1) || empty($hex2) || strlen($hex1) !== strlen($hex2)) {
            return 64;
        }

        self::initLookup();

        $bin1 = hex2bin($hex1);
        $bin2 = hex2bin($hex2);
        if ($bin1 === false || $bin2 === false) {
            return 64;
        }

        $dist = 0;
        $len = strlen($bin1);
        for ($i = 0; $i < $len; $i++) {
            $xor = ord($bin1[$i]) ^ ord($bin2[$i]);
            $dist += self::$bitCountLookup[$xor];
        }
        return $dist;
    }

    /**
     * Find existing Sale with duplicate slip.
     * Evaluates SHA-256 (exact), pHash (exact), and pHash Hamming distance <= threshold.
     */
    public function findDuplicate(string $sha256, ?string $phash = null, ?int $ignoreSaleId = null): ?Sale
    {
        // 1. Exact SHA-256 check
        if (!empty($sha256)) {
            $exactSha = Sale::where('payment_slip_hash', $sha256)
                ->when($ignoreSaleId, fn($q) => $q->where('id', '!=', $ignoreSaleId))
                ->orderBy('id', 'desc')
                ->first();

            if ($exactSha) {
                return $exactSha;
            }
        }

        // 2. Perceptual Hash check
        $algo = Setting::get('telegram_bot_slip_hash_algorithm', 'hybrid');
        if ($algo !== 'sha256' && !empty($phash)) {
            // 2A. Exact pHash match in indexed column
            $exactPhash = Sale::where('payment_slip_phash', $phash)
                ->when($ignoreSaleId, fn($q) => $q->where('id', '!=', $ignoreSaleId))
                ->orderBy('id', 'desc')
                ->first();

            if ($exactPhash) {
                return $exactPhash;
            }

            // 2B. Near-duplicate scan via Hamming distance
            $threshold = (int)Setting::get('telegram_bot_phash_threshold', 8);
            if ($threshold > 0) {
                $candidates = Sale::whereNotNull('payment_slip_phash')
                    ->when($ignoreSaleId, fn($q) => $q->where('id', '!=', $ignoreSaleId))
                    ->orderBy('id', 'desc')
                    ->take(500)
                    ->get(['id', 'reference_no', 'payment_slip_phash', 'created_at']);

                foreach ($candidates as $cand) {
                    $dist = self::hammingDistance($phash, $cand->payment_slip_phash);
                    if ($dist <= $threshold) {
                        return $cand;
                    }
                }
            }
        }

        // 3. Fallback to Cache for backward compatibility
        if (!empty($sha256) && Cache::has("tg_slip_hash_{$sha256}")) {
            $ref = Cache::get("tg_slip_hash_{$sha256}");
            $sale = Sale::where('reference_no', $ref)
                ->when($ignoreSaleId, fn($q) => $q->where('id', '!=', $ignoreSaleId))
                ->first();
            if ($sale) {
                return $sale;
            }
        }

        return null;
    }

    private static function initLookup(): void
    {
        if (self::$bitCountLookup === null) {
            self::$bitCountLookup = [];
            for ($i = 0; $i < 256; $i++) {
                self::$bitCountLookup[$i] = substr_count(decbin($i), '1');
            }
        }
    }
}
