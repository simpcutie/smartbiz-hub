<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class TransactionLog
{
    public static function record(string $action, string $reference, ?int $userId = null): void
    {
        Log::info($action, ['reference' => $reference, 'user_id' => $userId ?? auth()->id()]);
    }
}
