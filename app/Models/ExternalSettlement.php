<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalSettlement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payout_date' => 'date:Y-m-d',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function account()
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }
}
