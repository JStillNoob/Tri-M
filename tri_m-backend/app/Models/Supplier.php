<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['name', 'name_key'];

    protected $hidden = ['name_key', 'created_at', 'updated_at'];
}
