<?php

namespace App\Services\Import;

use App\Support\ColumnMatcher;
use App\Support\Parse;
use Carbon\Carbon;
use Throwable;

class OrderMapper
{
    /** @var array<string, list<string>> */
    public const FIELD_ALIASES = [
        'order_id' => ['orderid', 'orderno', 'ordernumber', 'order', 'id', 'invoice', 'invoiceno', 'orderref', 'reference'],
        'ordered_at' => ['orderdate', 'date', 'createdat', 'createdon', 'orderedat', 'datetime', 'placedat'],
        'status' => ['status', 'orderstatus', 'currentstatus'],
        'customer_name' => ['customername', 'customer', 'name', 'buyer', 'billingname'],
        'customer_contact' => ['phone', 'mobile', 'customerphone', 'contact', 'email', 'customeremail', 'mobilenumber'],
        'product_name' => ['product', 'productname', 'item', 'itemname', 'title'],
        'sku' => ['sku', 'productcode', 'itemcode', 'code', 'productid'],
        'quantity' => ['quantity', 'qty', 'units'],
        'unit_price' => ['unitprice', 'price', 'rate', 'sellingprice'],
        'line_total' => ['linetotal', 'total', 'amount', 'itemtotal'],
        'discount' => ['discount', 'discountamount'],
        'shipping' => ['shipping', 'shippingcost', 'deliverycharge', 'deliveryfee'],
        'courier' => ['courier', 'couriername', 'carrier', 'deliveryservice'],
        'consignment_id' => ['consignment', 'consignmentid', 'trackingid', 'tracking'],
        'employee' => ['employee', 'agent', 'assignedto', 'handledby', 'staff', 'confirmedby'],
        'city' => ['city', 'district', 'area', 'region'],
        'cancel_reason' => ['cancelreason', 'reason', 'cancellationreason'],
        'currency' => ['currency'],
        'utm_campaign' => ['utmcampaign', 'campaign'],
    ];

    /** @var array<string, string> */
    public const FIELD_LABELS = [
        'order_id' => 'Order ID *',
        'ordered_at' => 'Order Date',
        'status' => 'Order Status',
        'customer_name' => 'Customer Name',
        'customer_contact' => 'Customer Phone or Email (hashed)',
        'product_name' => 'Product / Item Name',
        'sku' => 'SKU / Product Code',
        'quantity' => 'Quantity',
        'unit_price' => 'Unit Price',
        'line_total' => 'Line / Order Total *',
        'discount' => 'Discount',
        'shipping' => 'Shipping Fee',
        'courier' => 'Courier Name',
        'consignment_id' => 'Consignment / Tracking ID',
        'employee' => 'Assigned Employee',
        'city' => 'City / District',
        'cancel_reason' => 'Cancel Reason',
        'currency' => 'Currency',
        'utm_campaign' => 'UTM / Campaign',
    ];

    /**
     * @param  list<string>  $headers
     * @return array<string, ?string>
     */
    public static function autoDetect(array $headers): array
    {
        return ColumnMatcher::match($headers, self::FIELD_ALIASES);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, ?string>  $mapping
     * @return array{ok: bool, reason?: string, data?: array<string, mixed>}
     */
    public function mapRow(array $row, array $mapping, string $defaultCurrency = 'BDT'): array
    {
        $val = fn (string $field): string => isset($mapping[$field], $row[$mapping[$field]])
            ? trim((string) $row[$mapping[$field]])
            : '';

        $orderId = $val('order_id');
        if ($orderId === '') {
            return ['ok' => false, 'reason' => 'missing_order_id'];
        }

        $rawDate = $val('ordered_at');
        $orderedAt = $rawDate !== '' ? $this->parseImportDate($rawDate) : now();
        if ($rawDate !== '' && $orderedAt === null) {
            return ['ok' => false, 'reason' => 'invalid_date'];
        }

        $productName = $val('product_name');
        $sku = $val('sku');
        $qty = $val('quantity') !== '' ? ($this->parseNumber($val('quantity')) ?? 1.0) : 1.0;
        $qtyInt = max(1, (int) round($qty));

        $unitPrice = $val('unit_price') !== '' ? $this->parseNumber($val('unit_price')) : null;
        $lineTotal = $val('line_total') !== '' ? $this->parseNumber($val('line_total')) : null;

        if ($unitPrice === null && $lineTotal === null) {
            return ['ok' => false, 'reason' => 'missing_amount'];
        }

        $hasProductColumn = ($mapping['product_name'] ?? null) !== null || ($mapping['sku'] ?? null) !== null;
        $hasProductOnRow = $productName !== '' || $sku !== '';

        $items = [];
        $explicitTotal = null;

        if ($hasProductColumn && $hasProductOnRow) {
            if (($unitPrice === null || $unitPrice === 0.0) && $lineTotal !== null && $lineTotal > 0.0) {
                $unitPrice = round($lineTotal / $qtyInt, 2);
            }
            $unitPrice = max(0.0, (float) ($unitPrice ?? 0.0));
            $items[] = [
                'product_id' => null,
                'sku' => $sku !== '' ? $sku : null,
                'name' => $productName !== '' ? $productName : ($sku !== '' ? $sku : 'Item'),
                'quantity' => $qtyInt,
                'unit_price' => $unitPrice,
            ];
        } else {
            // Without product info: whole-order mode; line_total (or unit_price * qty) is order total.
            $orderAmt = $lineTotal ?? round(max(0.0, (float) $unitPrice) * $qtyInt, 2);
            $explicitTotal = max(0.0, (float) $orderAmt);
        }

        $contact = $val('customer_contact');
        $customer = [];
        if ($val('customer_name') !== '') {
            $customer['name'] = $val('customer_name');
        }
        if ($contact !== '') {
            if (str_contains($contact, '@')) {
                $customer['email'] = $contact;
            } else {
                $customer['phone'] = $contact;
            }
        }

        $discount = max(0.0, (float) ($this->parseNumber($val('discount')) ?? 0.0));
        $shipping = max(0.0, (float) ($this->parseNumber($val('shipping')) ?? 0.0));
        $currency = $val('currency') !== '' ? strtoupper(substr($val('currency'), 0, 3)) : $defaultCurrency;

        return [
            'ok' => true,
            'raw_status' => $val('status') !== '' ? $val('status') : 'pending',
            'data' => [
                'id' => $orderId,
                'status' => $val('status') !== '' ? $val('status') : 'pending',
                'ordered_at' => $orderedAt?->toIso8601String(),
                'currency' => $currency,
                'discount' => $discount,
                'shipping' => $shipping,
                'total' => $explicitTotal,
                'courier' => $val('courier') !== '' ? $val('courier') : null,
                'consignment_id' => $val('consignment_id') !== '' ? $val('consignment_id') : null,
                'city' => $val('city') !== '' ? $val('city') : null,
                'cancel_reason' => $val('cancel_reason') !== '' ? $val('cancel_reason') : null,
                'utm_campaign' => $val('utm_campaign') !== '' ? $val('utm_campaign') : null,
                'customer' => $customer !== [] ? $customer : null,
                'employee' => $val('employee') !== '' ? ['name' => $val('employee')] : null,
                'items' => $items,
            ],
        ];
    }

    public function parseNumber(string $raw): ?float
    {
        $clean = Parse::toAsciiDigits(trim($raw));
        if ($clean === '') {
            return null;
        }

        $negative = false;
        if (preg_match('/^\((.+)\)$/', $clean, $m)) {
            $negative = true;
            $clean = trim($m[1]);
        }

        $num = Parse::num($clean);
        if ($num === null) {
            return null;
        }

        return $negative ? -$num : $num;
    }

    public function parseImportDate(string $raw): ?Carbon
    {
        $tz = config('app.timezone', 'Asia/Dhaka');
        $s = Parse::toAsciiDigits(trim($raw));
        if ($s === '') {
            return null;
        }

        // yyyymmdd
        if (preg_match('/^(19|20)\d{6}$/', $s)) {
            try {
                return Carbon::createFromFormat('Ymd', $s, $tz)->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        // Unix timestamp (10 digits)
        if (preg_match('/^\d{10}$/', $s)) {
            return Carbon::createFromTimestamp((int) $s, $tz);
        }

        // Excel serial date (e.g. 45678 or 45678.5)
        if (is_numeric($s)) {
            $serial = (float) $s;
            if ($serial >= 25000 && $serial <= 80000) {
                $days = (int) floor($serial);
                $secs = (int) round(($serial - $days) * 86400);

                return Carbon::create(1899, 12, 30, 0, 0, 0, $tz)
                    ->addDays($days)
                    ->addSeconds($secs);
            }
        }

        // Ambiguous a/b/yyyy is read as month/day (unless a > 12) per IMPORT_SPEC.md
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(\d{1,2}:\d{2}(?::\d{2})?))?$#', $s, $m)) {
            $first = (int) $m[1];
            $second = (int) $m[2];
            $timePart = $m[4] ?? '';
            $fmt = $first > 12 ? 'd/m/Y' : 'm/d/Y';
            if ($timePart !== '') {
                $fmt .= substr_count($timePart, ':') === 2 ? ' H:i:s' : ' H:i';
            }

            if ($fmt === 'm/d/Y' && $second > 31) {
                return null;
            }

            try {
                $dt = Carbon::createFromFormat($fmt, $s, $tz);
                $errors = Carbon::getLastErrors();
                if ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                    return null;
                }
                if ($dt !== false) {
                    return $timePart === '' ? $dt->startOfDay() : $dt;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return Parse::datetime($s, $tz);
    }
}
