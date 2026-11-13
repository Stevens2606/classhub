<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $fillable = ['email', 'code_hash', 'attempts', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];
}
