<?php

namespace App\Core;

/** API error with the contract shape { message, code, errors } and an HTTP status. */
class HttpError extends \RuntimeException
{
    public function __construct(public int $status, string $message, public string $errorCode = 'error', public ?array $errors = null)
    {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'Record'): self { return new self(404, "$what not found", 'not_found'); }
    public static function forbidden(string $msg = "You don't have permission to do this"): self { return new self(403, $msg, 'forbidden'); }
    public static function invalid(array $errors): self { return new self(422, (string) array_values($errors)[0], 'validation', $errors); }
    public static function unauthenticated(string $msg = 'Please sign in'): self { return new self(401, $msg, 'unauthenticated'); }
}
