<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'icon',
        'qr_code_image',
        'is_active',
        'is_default',
        'description',
        'account_number',
        'account_name',
        'is_bot_available',
    ];

    protected $appends = [
        'qr_code_image_url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'is_bot_available' => 'boolean',
    ];

    /**
     * Get full public URL for the QR code / payment method image
     */
    public function getQrCodeImageUrlAttribute(): ?string
    {
        if (empty($this->qr_code_image)) {
            return null;
        }
        if (str_starts_with($this->qr_code_image, 'http://') || str_starts_with($this->qr_code_image, 'https://')) {
            return $this->qr_code_image;
        }
        return asset('storage/' . ltrim($this->qr_code_image, '/'));
    }

    /**
     * Get local file path for Telegram Bot sendPhoto upload
     */
    public function getQrCodeLocalPath(): ?string
    {
        if (empty($this->qr_code_image)) {
            return null;
        }
        $val = $this->qr_code_image;
        if (str_contains($val, '/storage/')) {
            $parts = explode('/storage/', $val);
            $val = end($parts);
        }
        $rel = ltrim($val, '/');
        $storagePath = storage_path('app/public/' . $rel);
        if (file_exists($storagePath)) {
            return $storagePath;
        }
        $publicPath = public_path('storage/' . $rel);
        if (file_exists($publicPath)) {
            return $publicPath;
        }
        $rawPublic = public_path($rel);
        if (file_exists($rawPublic)) {
            return $rawPublic;
        }
        return null;
    }
}
