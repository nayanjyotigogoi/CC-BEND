<?php

namespace App\Shared;

use App\Core\HttpError;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads (menu photos, logo, UPI QR, bill photos, selfies). The browser compresses images to WebP first;
 * the API saves them under public/storage (served directly, no storage:link) and returns a URL.
 */
class Media
{
    public static function storeDataUrl(string $dataUrl, string $kind): string
    {
        if (!preg_match('#^data:(image/(png|jpe?g|webp|gif));base64,(.+)$#', $dataUrl, $m)) throw HttpError::invalid(['file' => 'Upload a PNG, JPG or WebP image']);
        $bytes = base64_decode($m[3], true);
        if ($bytes === false || strlen($bytes) > 2 * 1024 * 1024) throw HttpError::invalid(['file' => 'Image must be under 2 MB']);
        $ext = $m[2] === 'jpeg' ? 'jpg' : $m[2];
        $path = ctx()->tenantId() . "/$kind/" . bin2hex(random_bytes(8)) . ".$ext";
        Storage::disk('public')->put($path, $bytes);
        return rtrim(config('app.url'), '/') . '/storage/' . $path;
    }

    /** Fields that may hold a just-uploaded image: stored once here, for every module. */
    public static function storeFields(array $data, array $fields, string $kind): array
    {
        foreach ($fields as $f) {
            if (!empty($data[$f]) && is_string($data[$f]) && str_starts_with($data[$f], 'data:')) $data[$f] = self::storeDataUrl($data[$f], $kind);
        }
        return $data;
    }
}
