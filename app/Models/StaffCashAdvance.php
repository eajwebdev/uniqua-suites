<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffCashAdvance extends Model
{
    protected $guarded = [];

    protected $casts = [
        'advance_date' => 'date:Y-m-d',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
