<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['branch_id', 'no_order', 'total'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
