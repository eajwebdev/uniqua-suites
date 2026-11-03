<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffRepayment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'repaid_on' => 'date:Y-m-d',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
