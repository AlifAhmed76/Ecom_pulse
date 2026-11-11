<?php

namespace App\Services\Marketing;

use App\Services\Import\OrderMapper;

class AdMapper
{
    public const SUPPORTED_PLATFORMS = ['meta', 'google', 'tiktok', 'other'];

    /**
     * Exact fields from docs/IMPORT_SPEC.md (Ad report - DO NOT GUESS COLUMNS).
     *
     * @var array<string, string>
     */
    public const FIELD_LABELS = [
        'date' => 'Reporting Date *',
        'campaign' => 'Campaign Name (matches orders.utm_campaign) *',
        'spend' => 'Ad Spend *',
        'impressions' => 'Impressions',
        'clicks' => 'Clicks',
        'platform_conversions' => 'Platform Conversions (Reported)',
        'platform_revenue' => 'Platform Conversion Value / Revenue (Reported)',
    ];

    public function __construct(private readonly OrderMapper $orderMapper = new OrderMapper())
    {
    }

    /**
     * Deliberately returns all nulls: ad column names must be mapped explicitly
     * by the owner until verified against a real ad export CSV (UNVERIFIED_AGAINST_REAL_DATA).
     *
     * @return array<string, null>
     */
    public static function emptyMapping(): array
    {
        return array_fill_keys(array_keys(self::FIELD_LABELS), null);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, ?string>  $mapping
     * @return array{ok: bool, reason?: string, data?: array<string, mixed>}
     */
    public function mapRow(array $row, array $mapping): array
    {
        $val = fn (string $field): string => isset($mapping[$field], $row[$mapping[$field]])
            ? trim((string) $row[$mapping[$field]])
            : '';

        $rawDate = $val('date');
        $campaign = $val('campaign');
        $rawSpend = $val('spend');

        if ($rawDate === '' && $campaign === '' && $rawSpend === '') {
            return ['ok' => false, 'reason' => 'empty_row'];
        }

        if ($campaign === '') {
            return ['ok' => false, 'reason' => 'missing_campaign'];
        }

        $date = $rawDate !== '' ? $this->orderMapper->parseImportDate($rawDate) : null;
        if ($date === null) {
            return ['ok' => false, 'reason' => 'invalid_date'];
        }

        $spend = $rawSpend !== '' ? $this->orderMapper->parseNumber($rawSpend) : null;
        if ($spend === null || $spend < 0.0) {
            return ['ok' => false, 'reason' => 'invalid_spend'];
        }

        $impressions = 0;
        if ($val('impressions') !== '') {
            $imp = $this->orderMapper->parseNumber($val('impressions'));
            if ($imp === null || $imp < 0.0) {
                return ['ok' => false, 'reason' => 'invalid_metric'];
            }
            $impressions = (int) round($imp);
        }

        $clicks = 0;
        if ($val('clicks') !== '') {
            $clk = $this->orderMapper->parseNumber($val('clicks'));
            if ($clk === null || $clk < 0.0) {
                return ['ok' => false, 'reason' => 'invalid_metric'];
            }
            $clicks = (int) round($clk);
        }

        $conversions = 0.0;
        if ($val('platform_conversions') !== '') {
            $conv = $this->orderMapper->parseNumber($val('platform_conversions'));
            if ($conv === null || $conv < 0.0) {
                return ['ok' => false, 'reason' => 'invalid_metric'];
            }
            $conversions = round($conv, 2);
        }

        $platformRevenue = null;
        if ($val('platform_revenue') !== '') {
            $rev = $this->orderMapper->parseNumber($val('platform_revenue'));
            if ($rev === null || $rev < 0.0) {
                return ['ok' => false, 'reason' => 'invalid_metric'];
            }
            $platformRevenue = round($rev, 2);
        }

        return [
            'ok' => true,
            'data' => [
                'date' => $date->format('Y-m-d'),
                'campaign' => mb_substr($campaign, 0, 200, 'UTF-8'),
                'spend' => round($spend, 2),
                'impressions' => max(0, $impressions),
                'clicks' => max(0, $clicks),
                'platform_conversions' => $conversions,
                'platform_revenue' => $platformRevenue,
            ],
        ];
    }
}
