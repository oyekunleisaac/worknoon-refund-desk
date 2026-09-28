<?php

namespace App\Models;

use App\Enums\Decision;
use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class RefundRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'decision' => Decision::class,
            'status' => RequestStatus::class,
            'refund_amount' => 'decimal:2',
            'item_ids' => 'array',
            'classification' => 'array',
            'policy_evaluation' => 'array',
            'risk_flags' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RefundRequest $request) {
            $request->reference ??= 'RF-'.strtoupper(Str::random(8));
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(RefundAuditEvent::class)->orderBy('id');
    }

    public function audit(string $stage, string $actor, string $summary, array $data = [], ?int $durationMs = null): RefundAuditEvent
    {
        return $this->auditEvents()->create([
            'stage' => $stage,
            'actor' => $actor,
            'summary' => $summary,
            'data' => $data ?: null,
            'duration_ms' => $durationMs,
        ]);
    }
}
