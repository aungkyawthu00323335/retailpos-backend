<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\Outlet;
use App\Models\TelegramNotificationLog;
use App\Models\TelegramRecipient;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\PurchaseAccountingService;
use App\Services\TelegramNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TelegramNotificationController extends Controller
{
    private TelegramNotificationService $telegram;
    private PurchaseAccountingService $accounting;

    public function __construct(
        TelegramNotificationService $telegram,
        PurchaseAccountingService $accounting
    ) {
        $this->telegram = $telegram;
        $this->accounting = $accounting;
    }

    private function resolveUserLabel(Request $request): string
    {
        $user = $request->user() ?? User::resolveFromRequest($request);
        if (!$user) {
            return 'System';
        }
        $name = trim((string)($user->name ?? 'Admin'));
        $role = trim((string)($user->role ?? ''));
        return $role !== '' ? "{$name} ({$role})" : $name;
    }

    private function serializeSettingsPayload(Request $request): array
    {
        $setting = TelegramSetting::current();
        $allowedOutlets = $this->accounting->extractAllowedOutletIds($request);

        $outlets = Outlet::query()
            ->when(!empty($allowedOutlets), fn ($q) => $q->whereIn('id', $allowedOutlets))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($o) => ['id' => (int)$o->id, 'name' => (string)$o->name])
            ->values()
            ->all();

        $outletNamesById = collect($outlets)->pluck('name', 'id')->all();

        $recipients = TelegramRecipient::query()
            ->when(!empty($allowedOutlets), function ($q) use ($allowedOutlets) {
                $q->where(function ($sub) use ($allowedOutlets) {
                    $sub->whereNull('outlet_id')->orWhereIn('outlet_id', $allowedOutlets);
                });
            })
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'chat_id',
                'outlet_id',
                'is_active',
                'notify_daily_summary',
                'notify_register_close',
            ])
            ->map(function (TelegramRecipient $r) use ($outletNamesById) {
                return [
                    'id' => (int)$r->id,
                    'name' => $r->name,
                    'chat_id' => $r->chat_id,
                    'outlet_id' => $r->outlet_id ? (int)$r->outlet_id : null,
                    'outlet_name' => $r->outlet_id ? ($outletNamesById[(int)$r->outlet_id] ?? ('Outlet #' . $r->outlet_id)) : null,
                    'is_active' => (bool)$r->is_active,
                    'notify_daily_summary' => (bool)$r->notify_daily_summary,
                    'notify_register_close' => (bool)$r->notify_register_close,
                ];
            })
            ->values()
            ->all();

        // Omit heavy message_preview text column on settings load (~90% smaller payload, faster initial load)
        $logs = TelegramNotificationLog::query()
            ->orderByDesc('id')
            ->limit(20)
            ->get([
                'id',
                'event_type',
                'recipient_name',
                'chat_id',
                'outlet_name',
                'triggered_by',
                'status',
                'error_message',
                'sent_at',
                'created_at',
            ])
            ->map(fn (TelegramNotificationLog $log) => [
                'id' => (int)$log->id,
                'event_type' => $log->event_type,
                'recipient_name' => $log->recipient_name,
                'chat_id' => $log->chat_id,
                'outlet_name' => $log->outlet_name,
                'triggered_by' => $log->triggered_by,
                'status' => $log->status,
                'message_preview' => '',
                'error_message' => $log->error_message,
                'sent_at' => $log->sent_at ? $log->sent_at->toDateTimeString() : ($log->created_at ? $log->created_at->toDateTimeString() : null),
            ])
            ->values()
            ->all();

        $plainToken = $setting->getPlainBotToken();

        return [
            'status' => 'success',
            'can_view_cost' => $this->accounting->canViewCost($request),
            'settings' => [
                'has_bot_token' => $plainToken !== '',
                'masked_bot_token' => $setting->getMaskedBotToken(),
                'is_enabled' => (bool)$setting->is_enabled,
                'auto_send_register_close' => (bool)$setting->auto_send_register_close,
                'auto_send_daily_enabled' => (bool)$setting->auto_send_daily_enabled,
                'auto_send_daily_time' => $setting->getNormalizedScheduleTime(),
                'last_auto_daily_sent_date' => $setting->last_auto_daily_sent_date,
                'last_auto_daily_sent_at' => $setting->last_auto_daily_sent_at
                    ? $setting->last_auto_daily_sent_at->toDateTimeString()
                    : null,
                'timezone' => $this->telegram->getStoreTimezone(),
                'include_profit' => (bool)$setting->include_profit,
                'include_registers' => (bool)$setting->include_registers,
                'message_language' => (string)($setting->message_language ?: 'my'),
                'updated_by' => $setting->updated_by,
                'updated_at' => $setting->updated_at ? $setting->updated_at->toDateTimeString() : null,
            ],
            'recipients' => $recipients,
            'outlets' => $outlets,
            'logs' => $logs,
        ];
    }

    public function getSettings(Request $request)
    {
        return response()->json($this->serializeSettingsPayload($request))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'bot_token' => 'nullable|string|max:255',
            'clear_bot_token' => 'nullable|boolean',
            'is_enabled' => 'required|boolean',
            'auto_send_register_close' => 'required|boolean',
            'auto_send_daily_enabled' => 'nullable|boolean',
            'auto_send_daily_time' => ['nullable', 'string', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],
            'include_profit' => 'required|boolean',
            'include_registers' => 'required|boolean',
            'message_language' => 'required|string|in:my,en',
            'recipients' => 'present|array|max:50',
            'recipients.*.id' => 'nullable|integer',
            'recipients.*.name' => 'required|string|min:1|max:120',
            'recipients.*.chat_id' => ['required', 'string', 'max:80', 'regex:/^-?[0-9]+$|^@[A-Za-z0-9_]{5,}$/'],
            'recipients.*.outlet_id' => 'nullable|integer|exists:outlets,id',
            'recipients.*.is_active' => 'required|boolean',
            'recipients.*.notify_daily_summary' => 'required|boolean',
            'recipients.*.notify_register_close' => 'required|boolean',
        ]);

        $allowedOutlets = $this->accounting->extractAllowedOutletIds($request);
        if (!empty($allowedOutlets)) {
            foreach ($validated['recipients'] as $r) {
                $oId = isset($r['outlet_id']) ? (int)$r['outlet_id'] : null;
                if ($oId !== null && $oId > 0 && !in_array($oId, $allowedOutlets, true)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized outlet selected for Telegram recipient.',
                    ], 403);
                }
            }
        }

        $triggeredBy = $this->resolveUserLabel($request);
        $tz = $this->telegram->getStoreTimezone();
        $nowStore = Carbon::now($tz);

        DB::transaction(function () use ($validated, $triggeredBy, $nowStore) {
            $setting = TelegramSetting::current();
            $oldTime = $setting->getNormalizedScheduleTime();

            if (!empty($validated['clear_bot_token'])) {
                $setting->setPlainBotToken(null);
            } elseif (isset($validated['bot_token'])) {
                $candidate = trim((string)$validated['bot_token']);
                if ($candidate !== '' && !str_contains($candidate, '****')) {
                    $setting->setPlainBotToken($candidate);
                }
            }

            $setting->is_enabled = (bool)$validated['is_enabled'];
            $setting->auto_send_register_close = (bool)$validated['auto_send_register_close'];

            if (array_key_exists('auto_send_daily_enabled', $validated)) {
                $setting->auto_send_daily_enabled = (bool)$validated['auto_send_daily_enabled'];
            }
            if (!empty($validated['auto_send_daily_time'])) {
                $newTime = trim((string)$validated['auto_send_daily_time']);
                $setting->auto_send_daily_time = $newTime;
                // If user reschedules to a future time later today after an earlier send, allow the new time to fire once
                if ($newTime !== $oldTime && $newTime > $nowStore->format('H:i') && $setting->last_auto_daily_sent_date === $nowStore->toDateString()) {
                    $setting->last_auto_daily_sent_date = null;
                }
            }

            $setting->include_profit = (bool)$validated['include_profit'];
            $setting->include_registers = (bool)$validated['include_registers'];
            $setting->message_language = (string)$validated['message_language'];
            $setting->updated_by = $triggeredBy;
            $setting->save();

            // Sync recipients cleanly
            $keptIds = [];
            foreach ($validated['recipients'] as $item) {
                $existing = !empty($item['id'])
                    ? TelegramRecipient::find((int)$item['id'])
                    : null;

                $data = [
                    'name' => trim((string)$item['name']),
                    'chat_id' => trim((string)$item['chat_id']),
                    'outlet_id' => !empty($item['outlet_id']) ? (int)$item['outlet_id'] : null,
                    'is_active' => (bool)$item['is_active'],
                    'notify_daily_summary' => (bool)$item['notify_daily_summary'],
                    'notify_register_close' => (bool)$item['notify_register_close'],
                ];

                if ($existing) {
                    $existing->update($data);
                    $keptIds[] = (int)$existing->id;
                } else {
                    $created = TelegramRecipient::create($data);
                    $keptIds[] = (int)$created->id;
                }
            }

            if (empty($keptIds)) {
                TelegramRecipient::query()->delete();
            } else {
                TelegramRecipient::query()->whereNotIn('id', $keptIds)->delete();
            }
        });

        $this->telegram->clearCaches();

        $payload = $this->serializeSettingsPayload($request);
        $payload['message'] = 'Telegram notification settings saved successfully.';
        return response()->json($payload)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    /**
     * Lightweight endpoint pinged periodically by desktop POS clients so custom daily schedules
     * fire reliably even on local Windows installations without external OS cron.
     */
    public function checkScheduledDaily()
    {
        $result = $this->telegram->dispatchScheduledDailyIfDue(false);
        $schedule = $this->telegram->getScheduleHint();
        return response()->json([
            'status' => !empty($result['skipped']) ? 'skipped' : (!empty($result['ok']) ? 'sent' : 'error'),
            'reason' => $result['reason'] ?? null,
            'sent_count' => $result['sent_count'] ?? 0,
            'failed_count' => $result['failed_count'] ?? 0,
            'message' => $result['message'] ?? null,
            'schedule' => $schedule,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    public function previewDailySummary(Request $request)
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
            'warehouse_id' => 'nullable|integer',
            'include_profit' => 'nullable|boolean',
            'include_registers' => 'nullable|boolean',
            'message_language' => 'nullable|string|in:my,en',
        ]);

        $allowedOutlets = $this->accounting->extractAllowedOutletIds($request);
        $outletId = !empty($validated['warehouse_id']) ? (int)$validated['warehouse_id'] : null;

        if ($outletId !== null && !empty($allowedOutlets) && !in_array($outletId, $allowedOutlets, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized outlet access.',
            ], 403);
        }

        $setting = TelegramSetting::current();
        $tz = $this->telegram->getStoreTimezone();
        $date = !empty($validated['date'])
            ? Carbon::parse($validated['date'], $tz)->toDateString()
            : Carbon::now($tz)->toDateString();

        $canViewCost = $this->accounting->canViewCost($request);
        $includeProfit = array_key_exists('include_profit', $validated)
            ? (bool)$validated['include_profit']
            : (bool)$setting->include_profit;
        $includeRegisters = array_key_exists('include_registers', $validated)
            ? (bool)$validated['include_registers']
            : (bool)$setting->include_registers;
        $lang = !empty($validated['message_language'])
            ? (string)$validated['message_language']
            : (string)($setting->message_language ?: 'my');

        $preview = $this->telegram->buildDailyBusinessSummary(
            $date,
            $outletId,
            $allowedOutlets,
            $canViewCost,
            $includeProfit,
            $includeRegisters,
            $lang,
            $this->resolveUserLabel($request)
        );

        return response()->json([
            'status' => 'success',
            'data' => $preview,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    public function sendDailySummary(Request $request)
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
            'warehouse_id' => 'nullable|integer',
            'recipient_ids' => 'nullable|array',
            'recipient_ids.*' => 'integer',
            'include_profit' => 'nullable|boolean',
            'include_registers' => 'nullable|boolean',
            'message_language' => 'nullable|string|in:my,en',
        ]);

        $allowedOutlets = $this->accounting->extractAllowedOutletIds($request);
        $outletId = !empty($validated['warehouse_id']) ? (int)$validated['warehouse_id'] : null;

        if ($outletId !== null && !empty($allowedOutlets) && !in_array($outletId, $allowedOutlets, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized outlet access.',
            ], 403);
        }

        $tz = $this->telegram->getStoreTimezone();
        $date = !empty($validated['date'])
            ? Carbon::parse($validated['date'], $tz)->toDateString()
            : Carbon::now($tz)->toDateString();

        $result = $this->telegram->sendDailySummary(
            $date,
            $outletId,
            $allowedOutlets,
            $this->accounting->canViewCost($request),
            $this->resolveUserLabel($request),
            $validated['recipient_ids'] ?? null,
            array_key_exists('include_profit', $validated) ? (bool)$validated['include_profit'] : null,
            array_key_exists('include_registers', $validated) ? (bool)$validated['include_registers'] : null,
            $validated['message_language'] ?? null
        );

        $statusCode = $result['ok'] ? 200 : 422;
        return response()->json([
            'status' => $result['ok'] ? 'success' : 'error',
            'message' => $result['message'],
            'sent_count' => $result['sent_count'],
            'failed_count' => $result['failed_count'],
            'results' => $result['results'],
        ], $statusCode);
    }

    public function sendRegisterSummary(Request $request, int $id)
    {
        $register = CashRegister::find($id);
        if (!$register) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cash register session not found.',
            ], 404);
        }

        $allowedOutlets = $this->accounting->extractAllowedOutletIds($request);
        if (!empty($allowedOutlets) && !in_array((int)$register->warehouse_id, $allowedOutlets, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized outlet access for this register.',
            ], 403);
        }

        $user = $request->user() ?? User::resolveFromRequest($request);
        $registerPayload = app(CashRegisterController::class)
            ->buildRegisterDetailsPayload($register, $user, false);

        $result = $this->telegram->sendRegisterNotification(
            $registerPayload,
            $this->resolveUserLabel($request),
            false
        );

        $statusCode = $result['ok'] ? 200 : 422;
        return response()->json([
            'status' => $result['ok'] ? 'success' : 'error',
            'message' => $result['message'],
            'sent_count' => $result['sent_count'],
            'failed_count' => $result['failed_count'],
            'results' => $result['results'],
        ], $statusCode);
    }

    public function sendTest(Request $request)
    {
        $validated = $request->validate([
            'chat_id' => ['nullable', 'string', 'max:80', 'regex:/^-?[0-9]+$|^@[A-Za-z0-9_]{5,}$/'],
            'recipient_name' => 'nullable|string|max:120',
            'bot_token' => 'nullable|string|max:255',
        ]);

        $result = $this->telegram->sendTestMessage(
            $validated['chat_id'] ?? null,
            $validated['recipient_name'] ?? null,
            $validated['bot_token'] ?? null,
            $this->resolveUserLabel($request)
        );

        $statusCode = $result['ok'] ? 200 : 422;
        return response()->json([
            'status' => $result['ok'] ? 'success' : 'error',
            'message' => $result['message'],
            'sent_count' => $result['sent_count'],
            'failed_count' => $result['failed_count'],
            'results' => $result['results'],
        ], $statusCode);
    }

    public function logs()
    {
        $logs = TelegramNotificationLog::query()
            ->orderByDesc('id')
            ->limit(50)
            ->get([
                'id',
                'event_type',
                'recipient_name',
                'chat_id',
                'outlet_name',
                'triggered_by',
                'status',
                'error_message',
                'sent_at',
                'created_at',
            ])
            ->map(fn (TelegramNotificationLog $log) => [
                'id' => (int)$log->id,
                'event_type' => $log->event_type,
                'recipient_name' => $log->recipient_name,
                'chat_id' => $log->chat_id,
                'outlet_name' => $log->outlet_name,
                'triggered_by' => $log->triggered_by,
                'status' => $log->status,
                'message_preview' => '',
                'error_message' => $log->error_message,
                'sent_at' => $log->sent_at ? $log->sent_at->toDateTimeString() : ($log->created_at ? $log->created_at->toDateTimeString() : null),
            ])
            ->values()
            ->all();

        return response()->json([
            'status' => 'success',
            'logs' => $logs,
        ]);
    }
}
