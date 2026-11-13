<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promo extends Model
{
    protected $fillable = ['code', 'type', 'value', 'max_uses', 'used_count', 'expires_at', 'is_active'];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }
        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /** Discount in IDR for the given price. Never more than the price itself. */
    public function discountFor(int $price): int
    {
        $discount = $this->type === 'percent'
            ? intdiv($price * min($this->value, 100), 100)
            : $this->value;

        return min($discount, $price);
    }
}
