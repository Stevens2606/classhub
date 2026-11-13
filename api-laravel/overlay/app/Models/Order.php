<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const FAILED = 'failed';

    protected $fillable = [
        'order_code', 'user_id', 'course_id', 'promo_id',
        'original_amount', 'discount_amount', 'gross_amount',
        'status', 'snap_token', 'redirect_url', 'paid_at',
    ];

    protected $casts = ['paid_at' => 'datetime'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function markPaid(): void
    {
        $this->update(['status' => self::PAID, 'paid_at' => now()]);
    }
}
