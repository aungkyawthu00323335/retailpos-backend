<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Setting;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    public function index()
    {
        if (Currency::count() === 0) {
            Currency::create(['name' => 'Myanmar Kyat', 'code' => 'MMK', 'symbol' => 'Ks', 'rate' => 1.0, 'is_default' => true, 'status' => 'Active']);
            Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'rate' => 1.0, 'is_default' => false, 'status' => 'Active']);
            Currency::create(['name' => 'Euro', 'code' => 'EUR', 'symbol' => '€', 'rate' => 0.92, 'is_default' => false, 'status' => 'Active']);
            Currency::create(['name' => 'Thai Baht', 'code' => 'THB', 'symbol' => '฿', 'rate' => 36.5, 'is_default' => false, 'status' => 'Active']);
            Currency::create(['name' => 'Singapore Dollar', 'code' => 'SGD', 'symbol' => 'S$', 'rate' => 1.34, 'is_default' => false, 'status' => 'Active']);
        }

        $currencies = Currency::orderBy('is_default', 'desc')->get();
        return response()->json(['status' => 'success', 'data' => $currencies]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:10|unique:currencies,code',
            'symbol' => 'required|string|max:10',
            'rate' => 'nullable|numeric|min:0',
            'is_default' => 'nullable|boolean',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        if (empty($validated['status'])) {
            $validated['status'] = 'Active';
        }
        $validated['rate'] = isset($validated['rate']) ? (float)$validated['rate'] : 1.0;

        $currency = Currency::create($validated);
        if ($currency->is_default) {
            Currency::where('id', '!=', $currency->id)->update(['is_default' => false]);
            Setting::updateOrCreate(['key' => 'default_currency'], ['value' => $currency->code]);
            Setting::updateOrCreate(['key' => 'currency_symbol'], ['value' => $currency->symbol]);
            Setting::updateOrCreate(['key' => 'currency_position'], ['value' => 'Suffix']);
        }

        return response()->json(['status' => 'success', 'message' => 'Currency created', 'data' => $currency], 201);
    }

    public function update(Request $request, $id)
    {
        $currency = Currency::find($id);
        if (!$currency) {
            return response()->json(['status' => 'error', 'message' => 'Currency not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'code' => 'sometimes|required|string|max:10|unique:currencies,code,' . $id,
            'symbol' => 'sometimes|required|string|max:10',
            'rate' => 'nullable|numeric|min:0',
            'is_default' => 'nullable|boolean',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        if (array_key_exists('rate', $validated) && $validated['rate'] === null) {
            $validated['rate'] = 1.0;
        }

        $currency->update($validated);
        if (isset($validated['is_default']) && $validated['is_default']) {
            Currency::where('id', '!=', $currency->id)->update(['is_default' => false]);
            Setting::updateOrCreate(['key' => 'default_currency'], ['value' => $currency->code]);
            Setting::updateOrCreate(['key' => 'currency_symbol'], ['value' => $currency->symbol]);
            Setting::updateOrCreate(['key' => 'currency_position'], ['value' => 'Suffix']);
        }

        return response()->json(['status' => 'success', 'message' => 'Currency updated', 'data' => $currency]);
    }

    public function setDefault($id)
    {
        $currency = Currency::find($id);
        if (!$currency) {
            return response()->json(['status' => 'error', 'message' => 'Currency not found'], 404);
        }

        Currency::where('id', '!=', $currency->id)->update(['is_default' => false]);
        $currency->update(['is_default' => true, 'status' => 'Active']);

        Setting::updateOrCreate(['key' => 'default_currency'], ['value' => $currency->code]);
        Setting::updateOrCreate(['key' => 'currency_symbol'], ['value' => $currency->symbol]);
        Setting::updateOrCreate(['key' => 'currency_position'], ['value' => 'Suffix']);

        return response()->json([
            'status' => 'success',
            'message' => "{$currency->name} ({$currency->symbol}) is now the default system currency",
            'data' => $currency,
        ]);
    }

    public function destroy($id)
    {
        $currency = Currency::find($id);
        if (!$currency) {
            return response()->json(['status' => 'error', 'message' => 'Currency not found'], 404);
        }
        $currency->delete();
        return response()->json(['status' => 'success', 'message' => 'Currency deleted']);
    }
}
