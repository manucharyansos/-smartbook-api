<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\TelegramLinkService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramLinkService $links,
        TelegramService $telegram,
    ): JsonResponse {
        $expectedSecret = trim((string) config('services.telegram.webhook_secret'));
        $receivedSecret = trim((string) $request->header('X-Telegram-Bot-Api-Secret-Token'));

        if ($expectedSecret !== '' && !hash_equals($expectedSecret, $receivedSecret)) {
            abort(403, 'Invalid Telegram webhook secret.');
        }

        $message = $request->input('message');
        $chatId = trim((string) data_get($message, 'chat.id'));
        $text = trim((string) data_get($message, 'text'));

        if ($chatId === '' || $text === '') {
            return response()->json(['ok' => true]);
        }

        if (!preg_match('/^\/start(?:@[A-Za-z0-9_]+)?(?:\s+(.+))?$/u', $text, $matches)) {
            $telegram->send($chatId, 'Vizit bot-ը ուղարկում է ամրագրման հաստատումներ և փոփոխություններ։ Միացրեք այն Vizit-ի ձեր էջից։');
            return response()->json(['ok' => true]);
        }

        $startPayload = trim((string) ($matches[1] ?? ''));
        if ($startPayload === '') {
            $telegram->send($chatId, 'Բացեք Vizit-ի ամրագրման կամ բիզնեսի կարգավորումների էջը և սեղմեք «Միացնել Telegram-ը»։');
            return response()->json(['ok' => true]);
        }

        $connection = $links->consume($startPayload);
        if (!$connection) {
            $telegram->send($chatId, 'Այս միացման հղումը անվավեր է կամ ժամկետանց։ Ստեղծեք նոր հղում Vizit-ում։');
            return response()->json(['ok' => true]);
        }

        if ($connection['type'] === 'user') {
            $model = User::query()->find($connection['id']);
            $label = 'Բիզնեսի Telegram ծանուցումները միացված են ✅';
        } else {
            $model = Client::query()->find($connection['id']);
            $label = 'Ձեր ամրագրումների Telegram ծանուցումները միացված են ✅';
        }

        if (!$model) {
            $telegram->send($chatId, 'Չհաջողվեց գտնել Vizit-ի համապատասխան հաշիվը։ Ստեղծեք նոր հղում։');
            return response()->json(['ok' => true]);
        }

        $model->forceFill(['telegram_chat_id' => $chatId])->save();
        Log::info('Telegram notifications connected.', [
            'connection_type' => $connection['type'],
            'model_id' => $connection['id'],
        ]);
        $telegram->send($chatId, $label);

        return response()->json(['ok' => true]);
    }
}
