<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Audit
{
    public static function log(string $action, ?Model $subject = null, array $meta = []): void
    {
        DB::table('activity_logs')->insert([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ? json_encode($meta) : null,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
