<?php

namespace App\Services\Courier;

use App\Services\Import\OrderMapper;
use App\Services\Sync\SyncService;
use App\Support\StatusMapper;

class CourierMapper
{
    /**
     * Exact fields from docs/IMPORT_SPEC.md (Courier report - DO NOT GUESS COLUMNS).
     *
     * @var array<string, string>
     */
    public const FIELD_LABELS = [
        'consignment_id' => 'Consignment / Tracking ID',
        'order_ref' => 'Order Reference / Invoice No',
        'status' => 'Courier Delivery Status *',
        'cod_amount' => 'COD / Expected Amount',
        'collected_amount' => 'Collected Amount',
        'delivery_fee' => 'Delivery Fee',
        'return_fee' => 'Return Fee',
        'area' => 'Area / City',
        'shipped_at' => 'Shipped At',
        'delivered_at' => 'Delivered At',
        'returned_at' => 'Returned At',
        'customer_contact' => 'Customer Phone or Email (hashed only)',
    ];

    public function __construct(private readonly OrderMapper $orderMapper = new OrderMapper())
    {
    }

    /**
     * Deliberately returns all nulls: courier column names must be mapped explicitly
     * by the user until verified against a real courier export file.
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
     * @return array{ok: bool, reason?: string, raw_status?: string, data?: array<string, mixed>}
     */
    public function mapRow(array $row, array $mapping, int $tenantId): array
    {
        $val = fn (string $field): string => isset($mapping[$field], $row[$mapping[$field]])
            ? trim((string) $row[$mapping[$field]])
            : '';

        $consignmentId = $val('consignment_id');
        $orderRef = $val('order_ref');
        $rawStatus = $val('status');
        $contact = $val('customer_contact');

        if ($consignmentId === '' && $orderRef === '' && $contact === '' && $rawStatus === '') {
            return ['ok' => false, 'reason' => 'empty_row'];
        }

        if ($rawStatus === '') {
            return ['ok' => false, 'reason' => 'missing_status'];
        }

        $shippedRaw = $val('shipped_at');
        $deliveredRaw = $val('delivered_at');
        $returnedRaw = $val('returned_at');

        $shippedAt = $shippedRaw !== '' ? $this->orderMapper->parseImportDate($shippedRaw) : null;
        $deliveredAt = $deliveredRaw !== '' ? $this->orderMapper->parseImportDate($deliveredRaw) : null;
        $returnedAt = $returnedRaw !== '' ? $this->orderMapper->parseImportDate($returnedRaw) : null;

        if (($shippedRaw !== '' && $shippedAt === null)
            || ($deliveredRaw !== '' && $deliveredAt === null)
            || ($returnedRaw !== '' && $returnedAt === null)) {
            return ['ok' => false, 'reason' => 'invalid_date'];
        }

        $customerKey = null;
        if ($contact !== '') {
            $customerKey = str_contains($contact, '@')
                ? SyncService::customerKeyFor($tenantId, null, $contact)
                : SyncService::customerKeyFor($tenantId, $contact, null);
        }

        if ($consignmentId === '' && $orderRef === '' && $customerKey === null) {
            return ['ok' => false, 'reason' => 'missing_match_identifier'];
        }

        $codAmount = $val('cod_amount') !== '' ? $this->orderMapper->parseNumber($val('cod_amount')) : null;
        $collectedAmount = $val('collected_amount') !== '' ? $this->orderMapper->parseNumber($val('collected_amount')) : null;
        $deliveryFee = $val('delivery_fee') !== '' ? $this->orderMapper->parseNumber($val('delivery_fee')) : null;
        $returnFee = $val('return_fee') !== '' ? $this->orderMapper->parseNumber($val('return_fee')) : null;

        return [
            'ok' => true,
            'raw_status' => $rawStatus,
            'data' => [
                'consignment_id' => $consignmentId !== '' ? mb_substr($consignmentId, 0, 120) : null,
                'order_ref' => $orderRef !== '' ? mb_substr($orderRef, 0, 120) : null,
                'customer_key' => $customerKey,
                'status' => StatusMapper::map($rawStatus),
                'source_status' => mb_substr($rawStatus, 0, 80),
                'cod_amount' => $codAmount !== null ? round($codAmount, 2) : null,
                'collected_amount' => $collectedAmount !== null ? round($collectedAmount, 2) : null,
                'delivery_fee' => $deliveryFee !== null ? round(max(0.0, $deliveryFee), 2) : null,
                'return_fee' => $returnFee !== null ? round(max(0.0, $returnFee), 2) : null,
                'area' => $val('area') !== '' ? mb_substr($val('area'), 0, 100) : null,
                'shipped_at' => $shippedAt,
                'delivered_at' => $deliveredAt,
                'returned_at' => $returnedAt,
            ],
        ];
    }
}
