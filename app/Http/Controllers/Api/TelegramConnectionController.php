<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TelegramLinkService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramConnectionController extends Controller
{
    public function show(Request $request, TelegramService $telegram): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->business_id, 403);

        return response()->json([
            'data' => [
                'available' => $telegram->enabled() && $telegram->botUrl() !== null,
                'connected' => trim((string) $user->telegram_chat_id) !== '',
                'bot_url' => $telegram->botUrl(),
            ],
        ]);
    }

    public function store(Request $request, TelegramLinkService $links): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->business_id, 403);

        try {
            $link = $links->issueForUser($user);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json([
                'message' => 'Telegram բոտը դեռ կարգավորված չէ։',
            ], 503);
        }

        return response()->json([
            'data' => [
                ...$link,
                'connected' => trim((string) $user->telegram_chat_id) !== '',
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->business_id, 403);

        $user->forceFill(['telegram_chat_id' => null])->save();

        return response()->json(['ok' => true]);
    }
}
