<?php

declare(strict_types=1);

namespace BarberLead\Tests\Unit;

use BarberLead\Support\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testPhoneNormalisation(): void
    {
        $this->assertSame('+359896610004', Text::normalizePhone('0896 610 004'));
        $this->assertSame('+359896610004', Text::normalizePhone('+359 896 610 004'));
        $this->assertSame('+359896610004', Text::normalizePhone('00359896610004'));
        $this->assertSame('+35929876543', Text::normalizePhone('02 987 6543'));
        $this->assertNull(Text::normalizePhone('12'));
        $this->assertNull(Text::normalizePhone(''));
        $this->assertNull(Text::normalizePhone(null));
    }

    public function testSlugAndTransliteration(): void
    {
        $this->assertSame('lozenets', Text::slug('Лозенец'));
        $this->assertSame('mladost-1a', Text::slug('Младост 1А'));
        $this->assertSame('zona-b-5', Text::slug('Zona B-5'));
    }

    public function testNameNormalisationIgnoresGenericWords(): void
    {
        $this->assertSame('prime', Text::normalizeName('Prime Barbershop'));
        $this->assertSame('prime', Text::normalizeName('PRIME barber shop'));
        $this->assertSame('praym', Text::normalizeName('Бръснарница Прайм'));
        $this->assertSame('barbershop', Text::normalizeName('Barbershop'));
    }

    public function testDomainAndUrl(): void
    {
        $this->assertSame('example.bg', Text::domain('https://www.Example.bg/za-nas'));
        $this->assertSame('example.bg', Text::domain('example.bg'));
        $this->assertNull(Text::domain(''));
        $this->assertSame('https://example.bg', Text::normalizeUrl('example.bg'));
        $this->assertNull(Text::normalizeUrl('javascript:alert(1)'));
        $this->assertNull(Text::normalizeUrl('ftp://example.bg'));
        $this->assertNull(Text::normalizeUrl('   '));
    }
}
