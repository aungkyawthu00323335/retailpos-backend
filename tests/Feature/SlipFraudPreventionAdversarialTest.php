<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockBalance;
use App\Services\PerceptualHashService;
use App\Services\TelegramBotService;

class SlipFraudPreventionAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected PerceptualHashService $hashService;
    protected TelegramBotService $botService;
    protected Outlet $outlet;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hashService = app(PerceptualHashService::class);
        $this->botService = app(TelegramBotService::class);

        $this->outlet = Outlet::create([
            'name' => 'Adversarial Test Outlet',
            'address' => 'Yangon',
            'phone' => '0911223344',
            'is_active' => true,
        ]);

        Setting::set('telegram_bot_warehouse_id', (string)$this->outlet->id);
        Setting::set('telegram_bot_token', '987654:ADVERSARIAL_TEST_TOKEN');
        Setting::set('telegram_bot_enabled', '1');
        Setting::set('telegram_bot_reject_duplicate_slip', '1');
        Setting::set('telegram_bot_slip_hash_algorithm', 'hybrid');
        Setting::set('telegram_bot_phash_threshold', '8');
        Setting::set('telegram_bot_duplicate_slip_action', 'reject');
        Setting::set('telegram_bot_enable_stock_reservation', '1');

        $this->product = Product::create([
            'name' => 'Adversarial Test Item',
            'code' => 'ADV-001',
            'sku' => 'ADV-SKU-001',
            'retail_price' => 15000,
            'selling_price' => 15000,
            'cost_price' => 9000,
            'unit_name' => 'Piece',
            'stock_quantity' => 100,
            'is_active' => true,
            'is_bot_available' => true,
        ]);

        StockBalance::create([
            'warehouse_id' => $this->outlet->id,
            'product_id' => $this->product->id,
            'quantity' => 100.0000,
            'reserved_quantity' => 0.0000,
        ]);
    }

    /**
     * Helper: Generate realistic payment slip JPEG bytes with visual content.
     */
    private function generateReceiptSlip(
        string $title,
        string $amount,
        string $refId,
        int $width = 320,
        int $height = 420,
        int $quality = 95,
        int $bgColorR = 250,
        int $bgColorG = 250,
        int $bgColorB = 250
    ): string {
        $img = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($img, $bgColorR, $bgColorG, $bgColorB);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);

        // Header banner
        $bannerColor = imagecolorallocate($img, 30, 80, 160);
        imagefilledrectangle($img, 0, 0, $width, 50, $bannerColor);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagestring($img, 5, 20, 15, $title, $white);

        // Text details
        $textColor = imagecolorallocate($img, 30, 30, 30);
        imagestring($img, 4, 20, 80, "Ref: " . $refId, $textColor);
        imagestring($img, 4, 20, 110, "Amount: " . $amount . " MMK", $textColor);
        imagestring($img, 3, 20, 140, "Date: 2026-10-16 12:30", $textColor);
        imagestring($img, 3, 20, 170, "Status: SUCCESS / PAID", $textColor);

        // Simulated QR block
        $qrColor = imagecolorallocate($img, 10, 10, 10);
        imagefilledrectangle($img, 80, 220, 240, 380, $qrColor);
        $qrInner = imagecolorallocate($img, 240, 240, 240);
        imagefilledrectangle($img, 100, 240, 220, 360, $qrInner);
        imagefilledrectangle($img, 120, 260, 200, 340, $qrColor);

        ob_start();
        imagejpeg($img, null, $quality);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /**
     * Helper: Recompress JPEG bytes with different quality factor.
     */
    private function recompressJpeg(string $jpegBytes, int $quality): string
    {
        $src = imagecreatefromstring($jpegBytes);
        ob_start();
        imagejpeg($src, null, $quality);
        $recompressed = ob_get_clean();
        imagedestroy($src);
        return $recompressed;
    }

    /**
     * Helper: Resize image bytes to new dimensions.
     */
    private function resizeImageBytes(string $imageBytes, int $newWidth, int $newHeight, int $quality = 90): string
    {
        $src = imagecreatefromstring($imageBytes);
        $origW = imagesx($src);
        $origH = imagesy($src);
        $scaled = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($scaled, $src, 0, 0, 0, 0, $newWidth, $newHeight, $origW, $origH);
        ob_start();
        imagejpeg($scaled, null, $quality);
        $resized = ob_get_clean();
        imagedestroy($scaled);
        imagedestroy($src);
        return $resized;
    }

    /**
     * Helper: Generate WavePay-style slip with visually distinct layout from KBZPay.
     * Uses a central yellow circular badge, yellow bottom bar, and centered card layout (no top banner).
     */
    private function generateWavePaySlip(
        string $title,
        string $amount,
        string $refId,
        int $width = 320,
        int $height = 420
    ): string {
        $img = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $width, $height, $bg);

        // Circular yellow logo badge in upper center
        $yellow = imagecolorallocate($img, 255, 204, 0);
        imagefilledellipse($img, (int)($width / 2), 70, 90, 90, $yellow);

        // WavePay bottom footer bar (no top banner)
        imagefilledrectangle($img, 0, $height - 50, $width, $height, $yellow);

        // Center card text
        $textColor = imagecolorallocate($img, 40, 40, 40);
        imagestring($img, 5, 40, 140, $title, $textColor);
        imagestring($img, 4, 30, 180, "Trans ID: " . $refId, $textColor);
        imagestring($img, 4, 30, 210, "Amount: " . $amount . " MMK", $textColor);

        ob_start();
        imagejpeg($img, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    // =========================================================================
    // CHALLENGE 1: Exact Binary Duplicate Rejection (F01, F03)
    // =========================================================================

    public function test_challenge_1_rest_exact_binary_duplicate_rejected_http_422(): void
    {
        Storage::fake('public');
        $imageBytes = $this->generateReceiptSlip('KBZPay Official', '15,000', 'KBZ-100293847');
        $sha = hash('sha256', $imageBytes);
        $file1 = UploadedFile::fake()->createWithContent('receipt_first.jpg', $imageBytes);

        // 1st Upload -> 200 Success
        $res1 = $this->postJson('/api/bot/upload-slip', ['slip' => $file1]);
        $res1->assertStatus(200);
        $res1->assertJson(['status' => 'success', 'message' => 'Slip uploaded']);
        $this->assertEquals($sha, $res1->json('slip_hash'));

        // Register sale with this exact SHA-256
        $sale = Sale::create([
            'reference_no' => 'BOT-20261016-EXACT-001',
            'customer_name' => 'Genuine Customer',
            'warehouse_name' => $this->outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'order_source' => 'telegram_bot',
            'payment_slip_hash' => $sha,
            'payment_slip_phash' => $res1->json('slip_phash'),
        ]);

        // 2nd Upload: Same binary payload under a renamed filename
        $file2 = UploadedFile::fake()->createWithContent('receipt_tampered_name.jpg', $imageBytes);
        $res2 = $this->postJson('/api/bot/upload-slip', ['slip' => $file2]);

        $res2->assertStatus(422);
        $res2->assertJson([
            'status' => 'error',
            'message' => 'Duplicate payment slip detected',
            'duplicate_order_ref' => 'BOT-20261016-EXACT-001',
            'slip_hash' => $sha,
        ]);
    }

    public function test_challenge_1_rest_field_alias_image_vs_slip_rejected(): void
    {
        Storage::fake('public');
        $imageBytes = $this->generateReceiptSlip('WaveMoney Transfer', '35,000', 'WAVE-88776655');
        $sha = hash('sha256', $imageBytes);

        // 1st Upload using 'image' parameter
        $file1 = UploadedFile::fake()->createWithContent('wave_slip.jpg', $imageBytes);
        $res1 = $this->postJson('/api/bot/upload-slip', ['image' => $file1]);
        $res1->assertStatus(200);

        Sale::create([
            'reference_no' => 'BOT-WAVE-001',
            'customer_name' => 'Wave Customer',
            'warehouse_name' => $this->outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Pending',
            'order_source' => 'telegram_bot',
            'payment_slip_hash' => $sha,
        ]);

        // 2nd Upload using 'slip' parameter
        $file2 = UploadedFile::fake()->createWithContent('wave_slip2.jpg', $imageBytes);
        $res2 = $this->postJson('/api/bot/upload-slip', ['slip' => $file2]);
        $res2->assertStatus(422);
        $res2->assertJson([
            'status' => 'error',
            'duplicate_order_ref' => 'BOT-WAVE-001',
        ]);
    }

    public function test_challenge_1_telegram_channel_exact_binary_duplicate_rejected(): void
    {
        $imageBytes = $this->generateReceiptSlip('CBPay Mobile', '15,000', 'CBP-99887711');
        $sha = hash('sha256', $imageBytes);
        $phash = $this->hashService->computePhash($imageBytes);

        Sale::create([
            'reference_no' => 'BOT-CBPAY-001',
            'customer_name' => 'Previous Customer',
            'warehouse_name' => $this->outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'order_source' => 'telegram_bot',
            'payment_slip_hash' => $sha,
            'payment_slip_phash' => $phash,
        ]);

        Http::fake([
            'https://api.telegram.org/bot*/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/cbpay_slip.jpg'],
            ], 200),
            'https://api.telegram.org/file/bot*/*' => Http::response($imageBytes, 200),
            'https://api.telegram.org/bot*/sendMessage*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 991],
            ], 200),
        ]);

        $chatId = 88776655;
        $update = [
            'update_id' => 7001,
            'message' => [
                'message_id' => 301,
                'from' => ['id' => $chatId, 'first_name' => 'Adversary'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'photo' => [
                    ['file_id' => 'tg_cbp_001', 'file_unique_id' => 'tg_uid_cbp', 'width' => 320, 'height' => 420],
                ],
            ],
        ];

        $this->botService->handleIncomingUpdate($update);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = $request->data()['text'] ?? '';
                return str_contains($text, 'Duplicate Slip Detected') && str_contains($text, 'BOT-CBPAY-001');
            }
            return false;
        });

        // Ensure no new sale was created for this duplicate slip
        $this->assertEquals(1, Sale::count());
    }

    public function test_challenge_1_telegram_uncompressed_document_exact_duplicate_rejected(): void
    {
        $imageBytes = $this->generateReceiptSlip('AYA Pay Slip', '20,000', 'AYA-44556677');
        $sha = hash('sha256', $imageBytes);

        Sale::create([
            'reference_no' => 'BOT-AYA-001',
            'customer_name' => 'AYA Buyer',
            'warehouse_name' => $this->outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'order_source' => 'telegram_bot',
            'payment_slip_hash' => $sha,
        ]);

        Http::fake([
            'https://api.telegram.org/bot*/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'documents/aya_slip.png'],
            ], 200),
            'https://api.telegram.org/file/bot*/*' => Http::response($imageBytes, 200),
            'https://api.telegram.org/bot*/sendMessage*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 992],
            ], 200),
        ]);

        $chatId = 77665544;
        $update = [
            'update_id' => 7002,
            'message' => [
                'message_id' => 302,
                'from' => ['id' => $chatId, 'first_name' => 'Doc Attacker'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'document' => [
                    'file_id' => 'tg_doc_aya',
                    'file_name' => 'aya_slip.png',
                    'mime_type' => 'image/png',
                    'file_size' => strlen($imageBytes),
                ],
            ],
        ];

        $this->botService->handleIncomingUpdate($update);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = $request->data()['text'] ?? '';
                return str_contains($text, 'Duplicate Slip Detected') && str_contains($text, 'BOT-AYA-001');
            }
            return false;
        });
    }

    // =========================================================================
    // CHALLENGE 2: Recompressed & Resized Perceptual Duplicate Detection (F02, F03)
    // =========================================================================

    public function test_challenge_2_recompressed_jpeg_detected_by_phash(): void
    {
        Storage::fake('public');

        // Master slip generated at quality 95
        $masterBytes = $this->generateReceiptSlip('KBZPay Voucher Master', '50,000', 'KBZ-MASTER-101', 320, 420, 95);
        $masterSha = hash('sha256', $masterBytes);
        $masterPhash = $this->hashService->computePhash($masterBytes);

        // Attacker recompresses with lossy JPEG quality 80
        $recompressedBytes = $this->recompressJpeg($masterBytes, 80);
        $recompressedSha = hash('sha256', $recompressedBytes);
        $recompressedPhash = $this->hashService->computePhash($recompressedBytes);

        // Adversarial assertion: SHA-256 binary hash MUST differ due to lossy DCT quantization
        $this->assertNotEquals($masterSha, $recompressedSha, 'Recompressed JPEG must have distinct SHA-256 hash');

        // pHash Hamming distance MUST be <= default threshold (8)
        $distance = PerceptualHashService::hammingDistance($masterPhash, $recompressedPhash);
        $this->assertLessThanOrEqual(8, $distance, "Hamming distance {$distance} must be <= 8 for recompressed slip");

        // Register sale with Master Slip
        Sale::create([
            'reference_no' => 'BOT-ORIGINAL-SLIP-888',
            'customer_name' => 'First Payer',
            'warehouse_name' => $this->outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'order_source' => 'telegram_bot',
            'payment_slip_hash' => $masterSha,
            'payment_slip_phash' => $masterPhash,
        ]);

        // Attempt upload of recompressed slip via REST -> MUST be caught by pHash!
        $fileRecompressed = UploadedFile::fake()->createWithContent('recompressed_slip.jpg', $recompressedBytes);
        $res = $this->postJson('/api/bot/upload-slip', ['slip' => $fileRecompressed]);

        $res->assertStatus(422);
        $res->assertJson([
            'status' => 'error',
            'message' => 'Duplicate payment slip detected',
            'duplicate_order_ref' => 'BOT-ORIGINAL-SLIP-888',
        ]);
    }

    public function test_challenge_2_resized_image_detected_by_phash(): void
    {
        // 400x520 original slip
        $origBytes = $this->generateReceiptSlip('KBZPay Voucher Large', '75,000', 'KBZ-RESIZE-001', 400, 520, 90);
        $origSha = hash('sha256', $origBytes);
        $origPhash = $this->hashService->computePhash($origBytes);

        // Resized down to 320x416 (e.g. phone camera compression or Telegram resize)
        $resizedBytes = $this->resizeImageBytes($origBytes, 320, 416, 85);
        $resizedSha = hash('sha256', $resizedBytes);
        $resizedPhash = $this->hashService->computePhash($resizedBytes);

        $this->assertNotEquals($origSha, $resizedSha);
        $distance = PerceptualHashService::hammingDistance($origPhash, $resizedPhash);
        $this->assertLessThanOrEqual(8, $distance, "Resized slip Hamming distance {$distance} must be <= 8");

        Sale::create([
            'reference_no' => 'BOT-RESIZED-ORIGINAL',
            'customer_name' => 'Original Customer',
            'warehouse_name' => $this->outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'order_source' => 'telegram_bot',
            'payment_slip_hash' => $origSha,
            'payment_slip_phash' => $origPhash,
        ]);

        // Telegram Bot intake with resized slip
        Http::fake([
            'https://api.telegram.org/bot*/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/resized_slip.jpg'],
            ], 200),
            'https://api.telegram.org/file/bot*/*' => Http::response($resizedBytes, 200),
            'https://api.telegram.org/bot*/sendMessage*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 993],
            ], 200),
        ]);

        $chatId = 334455;
        $update = [
            'update_id' => 7003,
            'message' => [
                'message_id' => 303,
                'from' => ['id' => $chatId, 'first_name' => 'Resize Adversary'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'photo' => [
                    ['file_id' => 'tg_resized_001', 'file_unique_id' => 'tg_uid_resized', 'width' => 320, 'height' => 416],
                ],
            ],
        ];

        $this->botService->handleIncomingUpdate($update);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = $request->data()['text'] ?? '';
                return str_contains($text, 'Duplicate Slip Detected') && str_contains($text, 'BOT-RESIZED-ORIGINAL');
            }
            return false;
        });
    }

    // =========================================================================
    // CHALLENGE 3: Genuine Distinct Slip Acceptance (False Positive Stress-Testing)
    // =========================================================================

    public function test_challenge_3_two_genuinely_distinct_slips_both_accepted(): void
    {
        Storage::fake('public');

        // Slip A: KBZPay for 15,000 MMK
        $slipA = $this->generateReceiptSlip('KBZPay Quick Pay', '15,000', 'KBZ-DISTINCT-A', 320, 420, 90, 240, 245, 255);
        $phashA = $this->hashService->computePhash($slipA);

        // Slip B: WavePay for 88,000 MMK with completely different structure (circular center, bottom footer)
        $slipB = $this->generateWavePaySlip('WaveMoney Voucher', '88,000', 'WAVE-DISTINCT-B', 320, 420);
        $phashB = $this->hashService->computePhash($slipB);

        $distance = PerceptualHashService::hammingDistance($phashA, $phashB);
        $this->assertGreaterThan(8, $distance, "Hamming distance between distinct slips ({$distance}) must be > 8");

        // 1. Upload Slip A via REST
        $fileA = UploadedFile::fake()->createWithContent('slip_a.jpg', $slipA);
        $resA = $this->postJson('/api/bot/upload-slip', ['slip' => $fileA]);
        $resA->assertStatus(200);

        Sale::create([
            'reference_no' => 'BOT-ORDER-A',
            'customer_name' => 'Customer A',
            'warehouse_name' => $this->outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'order_source' => 'telegram_bot',
            'payment_slip_hash' => $resA->json('slip_hash'),
            'payment_slip_phash' => $resA->json('slip_phash'),
        ]);

        // 2. Upload Slip B via REST -> MUST SUCCEED (no false positive)
        $fileB = UploadedFile::fake()->createWithContent('slip_b.jpg', $slipB);
        $resB = $this->postJson('/api/bot/upload-slip', ['slip' => $fileB]);

        $resB->assertStatus(200);
        $resB->assertJson([
            'status' => 'success',
            'message' => 'Slip uploaded',
        ]);
        $this->assertNotEquals($resA->json('slip_hash'), $resB->json('slip_hash'));
    }

    /**
     * EMPIRICAL CHALLENGE FINDING:
     * When two genuinely different transactions use the exact same visual receipt template
     * (e.g. KBZPay standard voucher layout with identical banner and QR coordinates),
     * 32x32 DCT perceptual hashing produces Hamming distance <= 8 (even 0), causing a false-positive rejection.
     */
    public function test_challenge_3_same_template_slips_document_template_collision_vulnerability(): void
    {
        Storage::fake('public');

        // Customer A: KBZPay slip for 15,000 MMK, Transaction TXN-101
        $slipA = $this->generateReceiptSlip('KBZPay Transfer', '15,000', 'KBZ-TXN-101');
        $phashA = $this->hashService->computePhash($slipA);

        // Customer B: KBZPay slip for 99,000 MMK, Transaction TXN-999 (same app template, different text)
        $slipB = $this->generateReceiptSlip('KBZPay Transfer', '99,000', 'KBZ-TXN-999');
        $phashB = $this->hashService->computePhash($slipB);

        // SHA-256 is correctly distinct
        $this->assertNotEquals(hash('sha256', $slipA), hash('sha256', $slipB));

        // However, pHash Hamming distance is 0 due to low-frequency DCT macro-structure invariance!
        $distance = PerceptualHashService::hammingDistance($phashA, $phashB);
        $this->assertLessThanOrEqual(8, $distance, 'Proves template collision: same bank template yields Hamming distance <= 8');

        // Register sale for Customer A
        $fileA = UploadedFile::fake()->createWithContent('slip_a.jpg', $slipA);
        $resA = $this->postJson('/api/bot/upload-slip', ['slip' => $fileA]);
        $resA->assertStatus(200);

        Sale::create([
            'reference_no' => 'BOT-CUSTOMER-A',
            'customer_name' => 'Customer A',
            'warehouse_name' => $this->outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'order_source' => 'telegram_bot',
            'payment_slip_hash' => $resA->json('slip_hash'),
            'payment_slip_phash' => $resA->json('slip_phash'),
        ]);

        // Customer B uploads their genuine slip B:
        // Under default 'hybrid' mode with threshold 8, Customer B is REJECTED as a duplicate of Customer A!
        $fileB = UploadedFile::fake()->createWithContent('slip_b.jpg', $slipB);
        $resB = $this->postJson('/api/bot/upload-slip', ['slip' => $fileB]);

        // Documents empirical collision: HTTP 422 false-positive rejection
        $this->assertEquals(422, $resB->getStatusCode());
        $this->assertEquals('BOT-CUSTOMER-A', $resB->json('duplicate_order_ref'));
    }

    public function test_challenge_3_telegram_distinct_slip_flow_accepts_order(): void
    {
        $slip = $this->generateReceiptSlip('KBZPay Unique Payment', '30,000', 'KBZ-UNIQUE-999');

        Http::fake([
            'https://api.telegram.org/bot*/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/unique_slip.jpg'],
            ], 200),
            'https://api.telegram.org/file/bot*/*' => Http::response($slip, 200),
            'https://api.telegram.org/bot*/sendMessage*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 994],
            ], 200),
        ]);

        $chatId = 11223344;

        // Set up active checkout session for customer
        Cache::put("tg_session_{$chatId}", [
            'is_cart' => true,
            'phone' => '09112233445',
            'address' => 'No 45, Golden Valley, Yangon',
            'payment_type' => 'KBZPay',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'variant_id' => null,
                    'quantity' => 2,
                    'price' => 15000,
                    'subtotal' => 30000,
                ],
            ],
        ], now()->addMinutes(30));

        $update = [
            'update_id' => 7004,
            'message' => [
                'message_id' => 304,
                'from' => ['id' => $chatId, 'first_name' => 'Honest Buyer'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'photo' => [
                    ['file_id' => 'tg_unique_001', 'file_unique_id' => 'tg_uid_unique', 'width' => 320, 'height' => 420],
                ],
            ],
        ];

        $this->botService->handleIncomingUpdate($update);

        // Verify order was successfully finalized and created
        $sale = Sale::where('telegram_chat_id', (string)$chatId)->first();
        $this->assertNotNull($sale, 'Sale must be created for genuine slip');
        $this->assertEquals('Paid', $sale->payment_status);
        $this->assertNotNull($sale->payment_slip_hash);
        $this->assertNotNull($sale->payment_slip_phash);

        // Verify Telegram message was receipt/order acknowledgement, NOT a duplicate warning
        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = $request->data()['text'] ?? '';
                return !str_contains($text, 'Duplicate Slip Detected');
            }
            return false;
        });
    }

    // =========================================================================
    // CHALLENGE 4: Corrupted, Malformed, and Non-Image Payload Handling (Robustness)
    // =========================================================================

    public function test_challenge_4_truncated_jpeg_gracefully_rejected_no_500(): void
    {
        Storage::fake('public');

        // Truncated JPEG: SOI and APP0 marker followed by truncated garbage
        $truncatedBytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00\x60\x00\x60\x00\x00" . str_repeat("\xAA\xBB\xCC", 10);
        $file = UploadedFile::fake()->createWithContent('corrupted.jpg', $truncatedBytes);

        $response = $this->postJson('/api/bot/upload-slip', ['slip' => $file]);

        // Must reject or handle gracefully, NEVER return 500
        $this->assertNotEquals(500, $response->getStatusCode(), 'Server must not crash with 500 on truncated image');
        $this->assertTrue(in_array($response->getStatusCode(), [200, 422]));
    }

    public function test_challenge_4_zero_byte_file_behavior_documents_vulnerability(): void
    {
        Storage::fake('public');

        $emptyFile = UploadedFile::fake()->create('empty_slip.jpg', 0);
        $response = $this->postJson('/api/bot/upload-slip', ['slip' => $emptyFile]);

        // EMPIRICAL CHALLENGER OBSERVATION:
        // Expected secure behavior: HTTP 422 (reject empty slip).
        // Actual implementation behavior: HTTP 200, accepts 0-byte file, slip_phash is null,
        // and marks order as Paid if order_id is provided.
        $this->assertNotEquals(500, $response->getStatusCode(), 'Must not crash with 500');
        if ($response->getStatusCode() === 200) {
            $this->assertNull($response->json('slip_phash'), 'Empty slip produces null pHash yet was accepted by uploadSlip');
        }
    }

    public function test_challenge_4_fake_php_script_as_jpg_behavior(): void
    {
        Storage::fake('public');

        $phpPayload = "<?php phpinfo(); ?>";
        $file = UploadedFile::fake()->createWithContent('exploit.jpg', $phpPayload);

        $response = $this->postJson('/api/bot/upload-slip', ['slip' => $file]);

        $this->assertNotEquals(500, $response->getStatusCode(), 'Must not crash with 500');
        if ($response->getStatusCode() === 200) {
            $this->assertNull($response->json('slip_phash'), 'Corrupt non-image produces null pHash yet was accepted by uploadSlip');
        }
    }

    public function test_challenge_4_missing_payload_returns_422(): void
    {
        $response = $this->postJson('/api/bot/upload-slip', []);
        $response->assertStatus(422);
        $this->assertNotEquals(500, $response->getStatusCode());
    }

    public function test_challenge_4_telegram_non_image_document_gracefully_warns(): void
    {
        Http::fake([
            'https://api.telegram.org/bot*/sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 995]], 200),
        ]);

        $chatId = 55443322;
        $update = [
            'update_id' => 7005,
            'message' => [
                'message_id' => 305,
                'from' => ['id' => $chatId, 'first_name' => 'Doc Sender'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'document' => [
                    'file_id' => 'tg_pdf_001',
                    'file_name' => 'malicious_receipt.pdf',
                    'mime_type' => 'application/pdf',
                    'file_size' => 45000,
                ],
            ],
        ];

        $this->botService->handleIncomingUpdate($update);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = $request->data()['text'] ?? '';
                // Myanmar invalid doc warning: "⚠️ ကျေးဇူးပြု၍ ကုန်ပစ္စည်း သို့မဟုတ် ငွေလွှဲပြေစာ ဓာတ်ပုံ (Image) သာ ပေးပို့ပေးပါရှင်။ 🙏"
                return str_contains($text, 'Image') || str_contains($text, 'ဓာတ်ပုံ');
            }
            return false;
        });
    }

    public function test_challenge_4_telegram_file_download_failure_handled_gracefully(): void
    {
        // Mock Telegram getFile returning 404 or error
        Http::fake([
            'https://api.telegram.org/bot*/getFile*' => Http::response(['ok' => false, 'description' => 'File not found'], 404),
            'https://api.telegram.org/bot*/sendMessage*' => Http::response(['ok' => true, 'result' => ['message_id' => 996]], 200),
        ]);

        $chatId = 66554433;
        $update = [
            'update_id' => 7006,
            'message' => [
                'message_id' => 306,
                'from' => ['id' => $chatId, 'first_name' => 'Fail Customer'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'photo' => [
                    ['file_id' => 'tg_missing_file_id', 'file_unique_id' => 'tg_uid_missing', 'width' => 100, 'height' => 100],
                ],
            ],
        ];

        $this->botService->handleIncomingUpdate($update);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'sendMessage')) {
                $text = $request->data()['text'] ?? '';
                return str_contains($text, 'ဓာတ်ပုံ ရယူ၍ မရပါရှင်');
            }
            return false;
        });
    }

    // =========================================================================
    // CHALLENGE 5: Algorithm Switching & Threshold Sensitivity Stress-Testing
    // =========================================================================

    public function test_challenge_5_sha256_only_algorithm_ignores_phash_near_duplicates(): void
    {
        Storage::fake('public');
        Setting::set('telegram_bot_slip_hash_algorithm', 'sha256');

        $masterBytes = $this->generateReceiptSlip('KBZPay Master', '10,000', 'KBZ-ALGO-101');
        $recompressedBytes = $this->recompressJpeg($masterBytes, 75);

        // Register sale with Master
        $file1 = UploadedFile::fake()->createWithContent('master.jpg', $masterBytes);
        $res1 = $this->postJson('/api/bot/upload-slip', ['slip' => $file1]);
        $res1->assertStatus(200);

        Sale::create([
            'reference_no' => 'BOT-ALGO-001',
            'customer_name' => 'Algo Customer',
            'warehouse_name' => $this->outlet->name,
            'date' => now()->format('Y-m-d H:i:s'),
            'status' => 'Completed',
            'order_source' => 'telegram_bot',
            'payment_slip_hash' => $res1->json('slip_hash'),
            'payment_slip_phash' => $res1->json('slip_phash'),
        ]);

        // When algorithm is explicitly configured to 'sha256', recompressed variant with different SHA-256 is permitted
        $file2 = UploadedFile::fake()->createWithContent('recompressed.jpg', $recompressedBytes);
        $res2 = $this->postJson('/api/bot/upload-slip', ['slip' => $file2]);
        $res2->assertStatus(200);

        // Exact binary duplicate MUST still be rejected under sha256 mode
        $file3 = UploadedFile::fake()->createWithContent('exact_dup.jpg', $masterBytes);
        $res3 = $this->postJson('/api/bot/upload-slip', ['slip' => $file3]);
        $res3->assertStatus(422);
    }
}
