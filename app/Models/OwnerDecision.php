<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OwnerDecision extends Model
{
    protected $guarded = [];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
