<?php

namespace Tests\Unit;

use App\Support\Parse;
use App\Support\StatusMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatusMapperAndParseTest extends TestCase
{
    #[DataProvider('statusCases')]
    public function test_maps_all_status_words(string $input, string $expected): void
    {
        $this->assertSame($expected, StatusMapper::map($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function statusCases(): array
    {
        $words = [
            'pending' => 'pending',
            'new' => 'pending',
            'placed' => 'pending',
            'processing' => 'pending',
            'wc-processing' => 'pending',
            'on hold' => 'pending',
            'on-hold' => 'pending',
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
            'ready_to_ship' => 'confirmed',
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
            'WC-COMPLETED' => 'delivered',
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

            'partially weird custom' => 'other',
            '' => 'other',
        ];

        $out = [];
        foreach ($words as $raw => $expected) {
            $out[$raw === '' ? 'empty' : $raw] = [$raw, $expected];
        }

        return $out;
    }

    public function test_parse_helpers(): void
    {
        $this->assertSame(1250.5, Parse::num('৳ 1,250.50'));
        $this->assertSame(3450.0, Parse::num('BDT ৩,৪৫০'));
        $this->assertNull(Parse::num('n/a'));
        $this->assertSame(42, Parse::intNum('৪২'));
        $this->assertSame('2026-03-15', Parse::date('15/03/2026'));
        $this->assertSame('2026-03-15 10:20:00', Parse::datetime('2026-03-15T10:20:00+06:00')?->format('Y-m-d H:i:s'));
        $this->assertNull(Parse::datetime(''));
    }
}
