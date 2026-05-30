<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardProduct extends Model
{
    protected $table = 'v2_card_products';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
