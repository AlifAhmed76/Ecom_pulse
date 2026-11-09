<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public static function record(
        string $action,
        string $details = '',
        ?int $tenantId = null,
        ?int $userId = null
    ): self {
        if ($tenantId === null && $userId === null && Auth::check()) {
            /** @var User $authUser */
            $authUser = Auth::user();
            $tenantId = $authUser->tenant_id;
            $userId = $authUser->id;
        }

        $cleanAction = mb_substr(trim($action), 0, 80, 'UTF-8');
        $cleanDetails = self::sanitizeDetails($details);
        $createdAtStr = now()->format('Y-m-d H:i:s');

        $rowId = DB::transaction(function () use ($tenantId, $userId, $cleanAction, $cleanDetails, $createdAtStr): int {
            $last = DB::table('audit_logs')
                ->orderByDesc('id')
                ->limit(1)
                ->lockForUpdate()
                ->first(['hash']);

            $prevHash = $last !== null ? (string) $last->hash : null;
            $hash = self::computeHash($prevHash, $tenantId, $userId, $cleanAction, $cleanDetails, $createdAtStr);

            return DB::table('audit_logs')->insertGetId([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'action' => $cleanAction,
                'details' => $cleanDetails,
                'prev_hash' => $prevHash,
                'hash' => $hash,
                'created_at' => $createdAtStr,
            ]);
        });

        return self::findOrFail($rowId);
    }

    /**
     * Stream and verify the full audit hash-chain without revealing global row counts.
     *
     * @return array{valid: bool, tenant_rows: int}
     */
    public static function verifyChain(?int $forTenantId = null): array
    {
        $expectedPrev = null;
        $tenantRows = 0;

        foreach (DB::table('audit_logs')->orderBy('id')->cursor() as $row) {
            $actualPrev = $row->prev_hash !== null ? (string) $row->prev_hash : null;
            if ($actualPrev !== $expectedPrev) {
                return ['valid' => false, 'tenant_rows' => $tenantRows];
            }

            $computed = self::computeHash(
                $actualPrev,
                $row->tenant_id !== null ? (int) $row->tenant_id : null,
                $row->user_id !== null ? (int) $row->user_id : null,
                (string) $row->action,
                (string) $row->details,
                (string) $row->created_at
            );

            if (! hash_equals($computed, (string) $row->hash)) {
                return ['valid' => false, 'tenant_rows' => $tenantRows];
            }

            if ($forTenantId !== null && $row->tenant_id !== null && (int) $row->tenant_id === $forTenantId) {
                $tenantRows++;
            }

            $expectedPrev = (string) $row->hash;
        }

        return ['valid' => true, 'tenant_rows' => $tenantRows];
    }

    public static function computeHash(
        ?string $prevHash,
        ?int $tenantId,
        ?int $userId,
        string $action,
        string $details,
        string $createdAt
    ): string {
        $payload = implode('|', [
            $prevHash ?? '',
            $tenantId !== null ? (string) $tenantId : '',
            $userId !== null ? (string) $userId : '',
            $action,
            $details,
            $createdAt,
        ]);

        return hash('sha256', $payload);
    }

    private static function sanitizeDetails(string $details): string
    {
        $singleLine = (string) preg_replace('/[\r\n\t]+/', ' ', trim($details));
        $redacted = (string) preg_replace('/lys_[A-Za-z0-9]{16,}/', '[redacted]', $singleLine);

        return mb_substr($redacted, 0, 255, 'UTF-8');
    }
}
