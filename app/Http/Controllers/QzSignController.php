<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * QZ Tray message signing for silent printing.
 * Requires QZ_PRIVATE_KEY_PATH in .env pointing to private-key.pem.
 * Generate demo keys via QZ Tray > Advanced > Site Manager.
 */
class QzSignController extends Controller
{
    public function sign(Request $request)
    {
        $toSign = $request->query('request') ?? $request->input('request');
        if (empty($toSign)) {
            return response('Missing request parameter', 400)
                ->header('Content-Type', 'text/plain');
        }

        $keyPath = config('services.qz.private_key_path');
        if (! $keyPath || ! is_readable($keyPath)) {
            return response('QZ signing not configured', 503)
                ->header('Content-Type', 'text/plain');
        }

        $privateKey = openssl_get_privatekey(file_get_contents($keyPath));
        if (! $privateKey) {
            return response('Invalid private key', 500)
                ->header('Content-Type', 'text/plain');
        }

        $signature = null;
        $ok = openssl_sign($toSign, $signature, $privateKey, OPENSSL_ALGO_SHA512);
        openssl_free_key($privateKey);

        if (! $ok || ! $signature) {
            return response('Signing failed', 500)
                ->header('Content-Type', 'text/plain');
        }

        return response(base64_encode($signature))
            ->header('Content-Type', 'text/plain');
    }

    public function certificate()
    {
        $certPath = config('services.qz.certificate_path');
        if (! $certPath || ! is_readable($certPath)) {
            return response('QZ certificate not configured', 404)
                ->header('Content-Type', 'text/plain');
        }

        return response(file_get_contents($certPath))
            ->header('Content-Type', 'text/plain');
    }
}
