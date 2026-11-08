<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionBill extends Model
{
    public const UNPAID = 'unpaid';

    public const PAID = 'paid';

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'due_on' => 'date:Y-m-d',
        'paid_on' => 'date:Y-m-d',
        'amount_cents' => 'integer',
        'paid_amount_cents' => 'integer',
    ];

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
