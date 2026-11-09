<?php

namespace App\Support;

class StatusMapper
{
    public const CANONICAL = [
        'pending',
        'confirmed',
        'shipped',
        'delivered',
        'cancelled',
        'returned',
        'other',
    ];

    /** @var array<string, string> */
    private const MAP = [
        'pending' => 'pending',
        'new' => 'pending',
        'placed' => 'pending',
        'processing' => 'pending',
        'on hold' => 'pending',
        'awaiting payment' => 'pending',
        'awaiting confirmation' => 'pending',
        'awaiting payment/confirmation' => 'pending',
        'unconfirmed' => 'pending',
        'created' => 'pending',
        'open' => 'pending',
        'পেন্ডিং' => 'pending',
        'অপেক্ষমান' => 'pending',

        'confirmed' => 'confirmed',
        'approved' => 'confirmed',
        'accepted' => 'confirmed',
        'verified' => 'confirmed',
        'packed' => 'confirmed',
        'ready to ship' => 'confirmed',
        'কনফার্মড' => 'confirmed',
        'নিশ্চিত' => 'confirmed',

        'shipped' => 'shipped',
        'dispatched' => 'shipped',
        'in transit' => 'shipped',
        'out for delivery' => 'shipped',
        'picked up' => 'shipped',
        'with courier' => 'shipped',
        'শিপড' => 'shipped',

        'delivered' => 'delivered',
        'completed' => 'delivered',
        'complete' => 'delivered',
        'fulfilled' => 'delivered',
        'done' => 'delivered',
        'received' => 'delivered',
        'successful' => 'delivered',
        'ডেলিভারড' => 'delivered',
        'সম্পন্ন' => 'delivered',

        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
        'rejected' => 'cancelled',
        'failed' => 'cancelled',
        'void' => 'cancelled',
        'declined' => 'cancelled',
        'বাতিল' => 'cancelled',

        'returned' => 'returned',
        'refunded' => 'returned',
        'return' => 'returned',
        'rto' => 'returned',
        'return to origin' => 'returned',
        'ফেরত' => 'returned',
        'রিটার্ন' => 'returned',
    ];

    public static function map(?string $raw): string
    {
        if ($raw === null) {
            return 'other';
        }

        $normalized = mb_strtolower(trim($raw), 'UTF-8');
        if ($normalized === '') {
            return 'other';
        }

        if (str_starts_with($normalized, 'wc-')) {
            $normalized = substr($normalized, 3);
        }

        $normalized = str_replace(['-', '_'], ' ', $normalized);
        $normalized = (string) preg_replace('/\s+/u', ' ', trim($normalized));

        return self::MAP[$normalized] ?? 'other';
    }
}
