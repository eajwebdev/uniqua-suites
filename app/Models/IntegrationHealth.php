<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationHealth extends Model
{
    protected $guarded = [];

    protected $table = 'integration_health';

    protected $casts = [
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];
}
