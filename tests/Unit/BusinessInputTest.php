<?php

declare(strict_types=1);

namespace BarberLead\Tests\Unit;

use BarberLead\BusinessInput;
use PHPUnit\Framework\TestCase;

final class BusinessInputTest extends TestCase
{
    public function testValidInputIsNormalised(): void
    {
        [$data, $errors] = BusinessInput::parse([
            'name' => '  Test Barbers ', 'phone' => '0888 123 456', 'website_url' => 'www.test.bg',
            'area_id' => '7', 'rating' => '4,8', 'reviews_count' => '120', 'lat' => '42,68', 'website_status' => 'weak',
        ], [7 => 'Лозенец']);
        $this->assertSame([], $errors);
        $this->assertSame('Test Barbers', $data['name']);
        $this->assertSame('+359888123456', $data['phone_normalized']);
        $this->assertSame('https://www.test.bg', $data['website_url']);
        $this->assertSame('test.bg', $data['website_domain']);
        $this->assertSame(7, $data['area_id']);
        $this->assertSame(4.8, $data['rating']);
        $this->assertSame(42.68, $data['lat']);
        $this->assertSame('weak', $data['website_status']);
    }

    public function testInvalidInputIsRejected(): void
    {
        [, $errors] = BusinessInput::parse([
            'name' => '', 'email' => 'not-an-email', 'phone' => '12', 'instagram_url' => 'javascript:alert(1)',
            'area_id' => '999', 'rating' => '7', 'lead_score_override' => '150', 'website_status' => 'bogus', 'lat' => '100',
        ], [7 => 'x']);
        foreach (['name', 'email', 'phone', 'instagram_url', 'area_id', 'rating', 'lead_score_override', 'website_status', 'lat'] as $field) {
            $this->assertArrayHasKey($field, $errors, "expected an error for $field");
        }
    }
}
