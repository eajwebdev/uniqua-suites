<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    protected $guarded = [];

    protected $casts = ['times_copied' => 'integer'];

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
