<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityLog extends Model
{
    protected $table = 'v2_security_logs';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'duration' => 'integer',
        'status_code' => 'integer',
    ];
}
