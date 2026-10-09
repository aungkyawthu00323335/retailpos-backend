<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Basic Product Fields
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'sku' => ['nullable', 'string', 'max:100', 'unique:products,code', 'unique:products,sku', 'unique:product_variants,sku'],
            'code' => ['nullable', 'string', 'max:100', 'unique:products,code', 'unique:products,sku', 'unique:product_variants,sku'],
            'auto_generate_sku' => ['nullable', 'boolean'],
            'barcode' => ['nullable', 'string', 'max:100', 'unique:products,barcode', 'unique:product_variants,barcode'],
            'auto_generate_barcode' => ['nullable', 'boolean'],
            'barcode_symbology' => ['nullable', 'string', 'in:CODE128,CODE39,EAN8,EAN13,UPC'],
            'alternative_name' => ['nullable', 'string', 'max:255'],

            // Classification
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'category_name' => ['nullable', 'string', 'max:255'],
            'subcategory_id' => ['nullable', 'integer'],
            'subcategory_name' => ['nullable', 'string', 'max:255'],
            'sub_category_id' => ['nullable', 'integer'],
            'sub_category_name' => ['nullable', 'string', 'max:255'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer'],
            'supplier_name' => ['nullable', 'string', 'max:255'],

            // Pricing & Units
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'retail_price' => ['nullable', 'numeric', 'min:0'],
            'retail_prices' => ['nullable', 'array'],
            'retail_prices.*' => ['numeric', 'min:0'],
            'selling_prices' => ['nullable', 'array'],
            'selling_prices.*' => ['numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_prices' => ['nullable', 'array'],
            'wholesale_prices.*' => ['numeric', 'min:0'],
            'base_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'unit_name' => ['nullable', 'string', 'max:100'],
            'sale_unit_id' => ['nullable', 'integer'],
            'sale_unit' => ['nullable', 'string', 'max:100'],
            'purchase_unit_id' => ['nullable', 'integer'],
            'purchase_unit' => ['nullable', 'string', 'max:100'],
            'unit_links' => ['nullable', 'array'],
            'unit_links.*.product_variant_id' => ['nullable', 'integer'],
            'unit_links.*.variant_sku' => ['nullable', 'string', 'max:100'],
            'unit_links.*.unit_type' => ['required_with:unit_links', 'string', 'in:purchase,sale'],
            'unit_links.*.unit_id' => ['nullable', 'integer', 'min:1'],
            'unit_links.*.unit_name' => ['required_with:unit_links', 'string', 'max:100'],
            'unit_links.*.conversion_rate' => ['required_with:unit_links', 'numeric', 'gt:0'],
            'unit_links.*.is_default' => ['nullable', 'boolean'],
            'unit_links.*.is_active' => ['nullable', 'boolean'],

            // Stock & Variants
            'stock_alert' => ['nullable', 'numeric', 'min:0'],
            'alert_quantity' => ['nullable', 'numeric', 'min:0'],
            'has_variants' => ['nullable', 'boolean'],
            'variation_attributes' => ['nullable', 'array'],
            'variation_attributes.*.attribute_id' => ['nullable', 'integer'],
            'variation_attributes.*.attribute_name' => ['nullable', 'string'],
            'variation_attributes.*.value_ids' => ['nullable', 'array'],
            'variation_attributes.*.values' => ['nullable', 'array'],

            'variants' => ['nullable', 'array'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:100', 'distinct'],
            'variants.*.barcode' => ['nullable', 'string', 'max:100'],
            'variants.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.retail_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.retail_prices' => ['nullable', 'array'],
            'variants.*.retail_prices.*' => ['numeric', 'min:0'],
            'variants.*.selling_prices' => ['nullable', 'array'],
            'variants.*.selling_prices.*' => ['numeric', 'min:0'],
            'variants.*.wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.wholesale_prices' => ['nullable', 'array'],
            'variants.*.wholesale_prices.*' => ['numeric', 'min:0'],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'variants.*.image_url' => ['nullable', 'string'],
            'variants.*.attribute_value_ids' => ['nullable', 'array'],
            'variants.*.attribute_values' => ['nullable', 'array'],

            // Opening Stock
            'opening_stocks' => ['nullable', 'array'],
            'opening_stocks.*.warehouse_id' => ['nullable'],
            'opening_stocks.*.warehouse_name' => ['nullable', 'string'],
            'opening_stocks.*.variant_sku' => ['nullable', 'string'],
            'opening_stocks.*.unit_type' => ['nullable', 'string', 'in:base,purchase,sale'],
            'opening_stocks.*.unit_id' => ['nullable', 'integer', 'min:1'],
            'opening_stocks.*.unit_name' => ['nullable', 'string', 'max:100'],
            'opening_stocks.*.quantity' => ['nullable', 'numeric', 'min:0'],

            // Images & Meta
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['nullable'],
            'image_url' => ['nullable', 'string'],
            'primary_image_index' => ['nullable', 'integer', 'min:0', 'max:4'],
            'note' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string', 'in:Active,Inactive'],
            'is_active' => ['nullable', 'boolean'],
            'is_bot_available' => ['nullable', 'boolean'],
            'bot_price' => ['nullable', 'numeric', 'min:0'],

            // HARD EXCLUSIONS (Strictly Prohibited)
            'tax' => ['prohibited'],
            'tax_rate' => ['prohibited'],
            'tax_method' => ['prohibited'],
            'tax_type' => ['prohibited'],
            'TaxNet' => ['prohibited'],
            'expire_date' => ['prohibited'],
            'expiry_date' => ['prohibited'],
            'expired_at' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Product Name ထည့်ပေးပါ။',
            'name.min' => 'Product Name သည် အနည်းဆုံး စာလုံး ၃ လုံးရှိရပါမည်။',
            'sku.unique' => 'ဤ SKU / Product Code ကို အသုံးပြုပြီးဖြစ်ပါသည်။',
            'code.unique' => 'ဤ SKU / Product Code ကို အသုံးပြုပြီးဖြစ်ပါသည်။',
            'barcode.unique' => 'ဤ Barcode ကို အခြား Product သို့မဟုတ် Variant တွင် အသုံးပြုပြီးဖြစ်ပါသည်။',
            'variants.*.sku.distinct' => 'Variation Product ID / Code များ ထပ်တူမဖြစ်ရပါ။ (Duplicate Variant SKU)',
            'variants.*.sku.unique' => 'ဤ Variation Product ID ကို အခြား Product သို့မဟုတ် Variant တွင် အသုံးပြုပြီးဖြစ်ပါသည်။',
            'tax.prohibited' => 'Tax field များကို ဤ Product Create feature တွင် အသုံးမပြုပါ။',
            'tax_rate.prohibited' => 'Tax field များကို ဤ Product Create feature တွင် အသုံးမပြုပါ။',
            'tax_method.prohibited' => 'Tax field များကို ဤ Product Create feature တွင် အသုံးမပြုပါ။',
            'tax_type.prohibited' => 'Tax field များကို ဤ Product Create feature တွင် အသုံးမပြုပါ။',
            'TaxNet.prohibited' => 'Tax field များကို ဤ Product Create feature တွင် အသုံးမပြုပါ။',
            'expire_date.prohibited' => 'Expire/Expiry Date ကို ဤ Product Create feature တွင် အသုံးမပြုပါ။',
            'expiry_date.prohibited' => 'Expire/Expiry Date ကို ဤ Product Create feature တွင် အသုံးမပြုပါ။',
            'expired_at.prohibited' => 'Expire/Expiry Date ကို ဤ Product Create feature တွင် အသုံးမပြုပါ။',
        ];
    }
}
