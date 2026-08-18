<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = ['kdigr', 'nama'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
