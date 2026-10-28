<?php

namespace App\Shared;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** One place for outgoing HTTPS calls (Google sign-in check, photo import): user agent and CA bundle. */
class Net
{
    public static function http(int $timeout = 20): PendingRequest
    {
        $req = Http::withHeaders(['User-Agent' => 'CafeChalao/1.0 (+https://cafechalao.in)'])->timeout($timeout);
        $ca = config('cafechalao.ca_bundle');
        return $ca && is_file($ca) ? $req->withOptions(['verify' => $ca]) : $req;
    }
}
