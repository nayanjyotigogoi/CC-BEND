<?php

namespace App\Core;

/** Append-only audit log and in-app notifications, used by every module. */
class Audit
{
    public static function log(string $action, string $entity, ?string $entityId, mixed $old, mixed $new): void
    {
        $c = ctx();
        Db::create('audit_logs', [
            'user_id' => $c->user['id'] ?? null,
            'user_name' => $c->admin ? 'CafeChalao support · ' . $c->admin['name'] : ($c->user['name'] ?? $c->customer['name'] ?? 'System'),
            'action' => $action, 'entity' => $entity, 'entity_id' => $entityId, 'device' => $c->device, 'old_value' => $old, 'new_value' => $new,
        ], 'au');
    }

    public static function notify(string $audience, string $title, string $body, ?string $link = null, ?string $userId = null): void
    {
        Db::create('notifications', ['audience' => $audience, 'user_id' => $userId, 'title' => $title, 'body' => $body, 'link' => $link, 'read' => false], 'nt');
    }
}
