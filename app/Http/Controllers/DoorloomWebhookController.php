<?php

namespace App\Http\Controllers;

use App\Models\DoorloomIntegration;
use App\Support\DoorloomAdapter;
use Illuminate\Http\Request;

class DoorloomWebhookController extends Controller
{
    public function store(Request $request)
    {
        $integration = DoorloomIntegration::current();
        $secret = (string) ($integration->webhook_secret ?? '');
        $header = (string) $request->header('X-Doorloom-Signature', '');
        if ($secret === '' || $header === '' || ! DoorloomAdapter::verifySignature($secret, $request->getContent(), $header)) {
            return response()->json(['message' => 'Invalid Doorloom signature.'], 401);
        }

        $event = json_decode($request->getContent(), true);
        if (! is_array($event)) {
            return response()->json(['message' => 'Invalid event.'], 400);
        }

        DoorloomAdapter::apply($event);

        return response()->json(['ok' => true]);
    }
}
