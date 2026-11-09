<?php

namespace App\Services;

use App\Models\ApiCredential;
use App\Models\Website;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class CredentialService
{
    /**
     * @param  list<string>  $scopes
     * @return array{credential: ApiCredential, secret: string}
     */
    public static function issue(
        Website $website,
        array $scopes,
        ?CarbonInterface $expiresAt = null,
        ?int $createdBy = null
    ): array {
        $allowed = array_keys(ApiCredential::SCOPES);
        $cleanScopes = array_values(array_unique(array_intersect($scopes, $allowed)));

        $keyId = 'lyp_'.bin2hex(random_bytes(10));
        $secret = 'lys_'.bin2hex(random_bytes(32));

        $credential = new ApiCredential();
        $credential->forceFill([
            'website_id' => $website->id,
            'key_id' => $keyId,
            'secret_encrypted' => Crypt::encryptString($secret),
            'scopes' => $cleanScopes,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
        ])->save();

        return [
            'credential' => $credential,
            'secret' => $secret,
        ];
    }

    /**
     * @return array{credential: ApiCredential, secret: string}
     */
    public static function rotate(ApiCredential $old, ?int $createdBy = null): array
    {
        return DB::transaction(function () use ($old, $createdBy): array {
            $issued = self::issue(
                $old->website,
                $old->scopes ?? [],
                $old->expires_at,
                $createdBy
            );

            $grace = now()->addHour();
            if ($old->expires_at === null || $old->expires_at->gt($grace)) {
                $old->forceFill(['expires_at' => $grace])->save();
            }

            return $issued;
        });
    }

    public static function revoke(ApiCredential $credential): void
    {
        $credential->forceFill(['revoked_at' => now()])->save();
    }

    public static function secretOf(ApiCredential $credential): string
    {
        return Crypt::decryptString($credential->secret_encrypted);
    }
}
