<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function advances()
    {
        return $this->hasMany(StaffCashAdvance::class);
    }

    public function repayments()
    {
        return $this->hasMany(StaffRepayment::class);
    }
}
