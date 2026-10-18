<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IpBlacklist extends Model
{
    protected $table = 'v2_ip_blacklist';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
