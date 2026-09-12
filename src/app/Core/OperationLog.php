<?php
namespace App\Core;

class OperationLog
{
    public static function write(string $action, ?string $target = null, ?string $targetId = null, ?string $detail = null): void
    {
        try {
            Db::exec(
                'INSERT INTO operation_logs (user_id, action, target, target_id, detail, ip_address, created_at) VALUES (?,?,?,?,?,?,?)',
                [Auth::id(), $action, $target, $targetId, $detail, $_SERVER['REMOTE_ADDR'] ?? null, Clock::now()]
            );
        } catch (\Throwable $e) {
            error_log('operation_log failed: ' . $e->getMessage());
        }
    }
}
