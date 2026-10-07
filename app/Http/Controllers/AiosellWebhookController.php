<?php

namespace App\Http\Controllers;

use App\Models\AiosellBookingLink;
use App\Models\AiosellMessage;
use App\Support\AiosellClient;
use App\Support\AiosellReservationWriter;
use Illuminate\Http\Request;

class AiosellWebhookController extends Controller
{
    public function reservation(Request $request)
    {
        if (! AiosellClient::webhookAuthorized($request->header('Authorization'))) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['success' => false, 'message' => 'Invalid reservation.'], 400);
        }
        unset($payload['creditCard']);

        $applied = AiosellReservationWriter::apply($payload);

        return response()->json([
            'success' => $applied['ok'],
            'message' => $applied['message'],
        ], $applied['ok'] ? 200 : 409);
    }

    public function message(Request $request)
    {
        if (! AiosellClient::webhookAuthorized($request->header('Authorization'))) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['success' => false, 'message' => 'Invalid message.'], 400);
        }

        $messageId = trim((string) ($payload['message_id'] ?? ''));
        $conversationId = trim((string) ($payload['conversation_id'] ?? ''));
        $content = trim((string) ($payload['content'] ?? ''));
        if ($messageId === '' || $conversationId === '' || $content === '') {
            return response()->json(['success' => false, 'message' => 'Message id, conversation, and content are required.'], 400);
        }

        if (! AiosellMessage::query()->where('message_id', $messageId)->exists()) {
            $channel = trim((string) ($payload['channel'] ?? '')) ?: null;
            $otaId = trim((string) ($payload['booking_id'] ?? '')) ?: null;
            $passionId = null;
            if ($channel && $otaId) {
                $passionId = AiosellBookingLink::query()
                    ->where('channel', $channel)
                    ->where('booking_id', $otaId)
                    ->value('passion_booking_id');
            }
            $guest = is_array($payload['guest_details'] ?? null) ? $payload['guest_details'] : [];
            AiosellMessage::query()->create([
                'message_id' => $messageId,
                'conversation_id' => $conversationId,
                'hotel_id' => trim((string) ($payload['hotel_id'] ?? '')) ?: null,
                'channel' => $channel,
                'booking_id' => $otaId,
                'passion_booking_id' => $passionId,
                'sender_type' => trim((string) ($payload['sender_type'] ?? '')) ?: null,
                'content' => $content,
                'time_sent' => trim((string) ($payload['time_sent'] ?? '')) ?: null,
                'guest_name' => trim((string) ($guest['name'] ?? '')) ?: null,
                'guest_phone' => trim((string) ($guest['phone'] ?? '')) ?: null,
                'guest_email' => trim((string) ($guest['email'] ?? '')) ?: null,
            ]);
        }

        return response()->json(['success' => true, 'message' => 'received']);
    }
}
