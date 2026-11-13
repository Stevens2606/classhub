<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = ['title', 'description', 'price', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
