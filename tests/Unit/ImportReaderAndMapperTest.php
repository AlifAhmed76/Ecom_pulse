<?php

namespace Tests\Unit;

use App\Services\Import\OrderMapper;
use App\Services\Import\RowReader;
use App\Support\ColumnMatcher;
use Tests\TestCase;

class ImportReaderAndMapperTest extends TestCase
{
    private function makeTempFile(string $ext, string $content): string
    {
        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $path = $dir.'/imp_'.uniqid().'.'.$ext;
        file_put_contents($path, $content);

        return $path;
    }

    public function test_csv_reader_handles_utf8_bom_semicolon_tab_pipe_and_duplicate_headers(): void
    {
        $reader = new RowReader();

        $bomCsv = "\xEF\xBB\xBF\nOrder No;Status;Note;Note;Total\nORD-1;Delivered;A;B;500\n;\nORD-2;Pending;C;D;250\n";
        $csvPath = $this->makeTempFile('csv', $bomCsv);

        try {
            $preview = $reader->preview($csvPath, 'csv', 5);
            $this->assertSame(['Order No', 'Status', 'Note', 'Note (2)', 'Total'], $preview['header']);
            $this->assertCount(2, $preview['sample']);
            $this->assertSame('ORD-1', $preview['sample'][0]['Order No']);
            $this->assertSame('B', $preview['sample'][0]['Note (2)']);

            $rows = iterator_to_array($reader->iterate($csvPath, 'csv'));
            $this->assertCount(2, $rows);
            $this->assertSame('ORD-2', $rows[1]['Order No']);
        } finally {
            @unlink($csvPath);
        }

        $tabPath = $this->makeTempFile('csv', "order_id\tstatus\ttotal\nT-1\tconfirmed\t300\n");
        $pipePath = $this->makeTempFile('csv', "order_id|status|total\nP-1|shipped|400\n");

        try {
            $tabRows = iterator_to_array($reader->iterate($tabPath, 'csv'));
            $this->assertSame('T-1', $tabRows[0]['order_id']);
            $this->assertSame('300', $tabRows[0]['total']);

            $pipeRows = iterator_to_array($reader->iterate($pipePath, 'csv'));
            $this->assertSame('P-1', $pipeRows[0]['order_id']);
            $this->assertSame('400', $pipeRows[0]['total']);
        } finally {
            @unlink($tabPath);
            @unlink($pipePath);
        }
    }

    public function test_xlsx_reader_parses_first_sheet_only_and_formats_date_cells(): void
    {
        $reader = new RowReader();
        $path = base_path('tests/Fixtures/synthetic_orders.xlsx');

        $preview = $reader->preview($path, 'xlsx', 5);
        $this->assertSame('Order ID', $preview['header'][0]);
        $this->assertCount(3, $preview['sample']);
        $this->assertSame('XLSX-101', $preview['sample'][0]['Order ID']);
        $this->assertSame('2026-11-05 14:30:00', $preview['sample'][0]['Order Date']);

        $all = iterator_to_array($reader->iterate($path, 'xlsx'));
        $this->assertCount(3, $all);
        $ids = array_column($all, 'Order ID');
        $this->assertNotContains('SECOND-SHEET-IGNORED', $ids);
    }

    public function test_xml_reader_flattens_nested_customer_and_repeating_items(): void
    {
        $reader = new RowReader();
        $path = base_path('tests/Fixtures/synthetic_orders.xml');

        $preview = $reader->preview($path, 'xml', 5);
        $this->assertContains('id', $preview['header']);
        $this->assertContains('customer_name', $preview['header']);
        $this->assertContains('customer_phone', $preview['header']);
        $this->assertContains('item_name', $preview['header']);

        $rows = iterator_to_array($reader->iterate($path, 'xml'));
        $this->assertCount(2, $rows);
        $this->assertSame('XML-301', $rows[0]['id']);
        $this->assertSame('Farhana', $rows[0]['customer_name']);
        $this->assertSame('Silk Scarf', $rows[0]['item_name']);
        $this->assertSame('XML-301', $rows[1]['id']);
        $this->assertSame('Brooch', $rows[1]['item_name']);
    }

    public function test_xml_reader_rejects_doctype_and_entity_and_disallows_xls(): void
    {
        $reader = new RowReader();

        try {
            $reader->preview(base_path('tests/Fixtures/synthetic_xxe.xml'), 'xml');
            $this->fail('Expected DOCTYPE rejection');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('DOCTYPE', $e->getMessage());
        }

        $entityPath = $this->makeTempFile('xml', "<?xml version=\"1.0\"?><!ENTITY x \"1\"><orders/>");
        try {
            $reader->iterate($entityPath, 'xml')->valid();
            $this->fail('Expected ENTITY rejection');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('DOCTYPE', $e->getMessage());
        } finally {
            @unlink($entityPath);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported format [xls]');
        $reader->preview('dummy.xls', 'xls');
    }

    public function test_column_matcher_exact_then_long_contains_and_single_use(): void
    {
        $headers = [
            'Order No',
            'Primary Delivery Service',
            'my_sku_tag',
            'id_tag',
            'Customer Mobile Number',
            'Grand Amount',
        ];

        $matched = ColumnMatcher::match($headers, OrderMapper::FIELD_ALIASES);

        $this->assertSame('Order No', $matched['order_id']);
        $this->assertSame('Primary Delivery Service', $matched['courier']); // via deliveryservice (len 15)
        $this->assertNull($matched['sku']); // 'sku' len 3 < 5 -> does NOT fuzzy-match 'my_sku_tag'
        $this->assertSame('Customer Mobile Number', $matched['customer_contact']); // via mobilenumber (len 12)
        $this->assertNull($matched['customer_name']); // customer_contact must NOT be stolen by 'customer'
        $this->assertSame('Grand Amount', $matched['line_total']); // via amount (len 6)

        // Single-use guarantee: no column assigned twice
        $assigned = array_values(array_filter($matched, fn ($v) => $v !== null));
        $this->assertSame(count($assigned), count(array_unique($assigned)));

        // Prefix collision safety: longest alias wins in contains pass
        $collisionMatched = ColumnMatcher::match(
            ['Customer Phone', 'Customer Full Name', 'Product ID', 'Product Title'],
            OrderMapper::FIELD_ALIASES
        );
        $this->assertSame('Customer Phone', $collisionMatched['customer_contact']);
        $this->assertSame('Customer Full Name', $collisionMatched['customer_name']);
        $this->assertSame('Product ID', $collisionMatched['sku']);
        $this->assertSame('Product Title', $collisionMatched['product_name']);
    }

    public function test_order_mapper_parses_numbers_dates_whole_order_mode_and_free_items(): void
    {
        $mapper = new OrderMapper();

        // Number formatting
        $this->assertSame(-100.0, $mapper->parseNumber('(100)'));
        $this->assertSame(1250.5, $mapper->parseNumber('৳ 1,250.50'));
        $this->assertSame(1200.0, $mapper->parseNumber('১২০০'));
        $this->assertNull($mapper->parseNumber('N/A'));

        // Date formats
        $this->assertSame('2026-11-03', $mapper->parseImportDate('20261103')?->format('Y-m-d'));
        $this->assertSame('2026-11-05', $mapper->parseImportDate('46331')?->format('Y-m-d')); // Excel serial 2026-11-05
        $this->assertSame('2026-01-02', $mapper->parseImportDate('01/02/2026')?->format('Y-m-d')); // ambiguous -> m/d/Y
        $this->assertSame('2026-02-25', $mapper->parseImportDate('25/02/2026')?->format('Y-m-d')); // > 12 -> d/m/Y
        $this->assertNull($mapper->parseImportDate('25/25/2026'));

        $unixDt = $mapper->parseImportDate('1762300800');
        $this->assertNotNull($unixDt);
        $this->assertSame(1762300800, $unixDt->getTimestamp());

        // Whole-order mode (no product/sku column mapped)
        $wholeMapping = array_fill_keys(array_keys(OrderMapper::FIELD_ALIASES), null);
        $wholeMapping['order_id'] = 'id';
        $wholeMapping['line_total'] = 'total';
        $wholeMapping['status'] = 'status';

        $wholeRes = $mapper->mapRow(['id' => 'W-1', 'total' => '৳ 1,450', 'status' => 'confirmed'], $wholeMapping);
        $this->assertTrue($wholeRes['ok']);
        $this->assertSame([], $wholeRes['data']['items']);
        $this->assertSame(1450.0, $wholeRes['data']['total']);

        // Product row with 0 price freebie must NOT set explicit total = 0
        $itemMapping = array_fill_keys(array_keys(OrderMapper::FIELD_ALIASES), null);
        $itemMapping['order_id'] = 'id';
        $itemMapping['product_name'] = 'product';
        $itemMapping['unit_price'] = 'price';
        $itemMapping['line_total'] = 'total';

        $freeRes = $mapper->mapRow([
            'id' => 'O-9',
            'product' => 'Free Sticker',
            'price' => '0',
            'total' => '0',
        ], $itemMapping);
        $this->assertTrue($freeRes['ok']);
        $this->assertNull($freeRes['data']['total']);
        $this->assertCount(1, $freeRes['data']['items']);
        $this->assertSame(0.0, $freeRes['data']['items'][0]['unit_price']);

        // Skipped reasons
        $this->assertSame('missing_order_id', $mapper->mapRow(['id' => '', 'total' => '100'], $wholeMapping)['reason']);
        $this->assertSame('missing_amount', $mapper->mapRow(['id' => 'O-2', 'total' => ''], $wholeMapping)['reason']);

        $wholeMapping['ordered_at'] = 'date';
        $this->assertSame('invalid_date', $mapper->mapRow(['id' => 'O-3', 'total' => '100', 'date' => 'not-a-date'], $wholeMapping)['reason']);
    }
}
