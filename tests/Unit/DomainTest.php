<?php

namespace Tests\Unit;

use App\Support\Domain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DomainTest extends TestCase
{
    #[DataProvider('domainCases')]
    public function test_domain_normalization(string $input, ?string $expected): void
    {
        $this->assertSame($expected, Domain::normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function domainCases(): array
    {
        return [
            'full url with www port query' => ['https://WWW.Shop.Example.COM:8443/products?a=1#frag', 'shop.example.com'],
            'http trailing slash' => ['http://example.com/', 'example.com'],
            'trailing dot and www' => ['www.store.com.', 'store.com'],
            'localhost with port and path' => ['http://localhost:8000/admin', 'localhost'],
            'uppercase localhost' => ['  LOCALHOST  ', 'localhost'],
            'subdomain and hyphen' => ['shop-bd.example.com.bd', 'shop-bd.example.com.bd'],
            'rejects user info' => ['https://user:pass@example.com', null],
            'rejects at symbol' => ['admin@example.com', null],
            'rejects empty' => ['   ', null],
            'rejects single label' => ['intranet', null],
            'rejects spaces' => ['my shop.com', null],
            'rejects leading hyphen' => ['-bad.example.com', null],
            'rejects oversized domain' => [str_repeat('a', 250).'.com', null],
        ];
    }
}
