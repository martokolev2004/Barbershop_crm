<?php

declare(strict_types=1);

namespace BarberLead\Tests\Unit;

use BarberLead\Http\Response;
use BarberLead\Http\Router;
use BarberLead\Migrator;
use PHPUnit\Framework\TestCase;

final class RouterAndSqlTest extends TestCase
{
    public function testRouterMatchesNumericParamsOnly(): void
    {
        $r = new Router();
        $r->get('/businesses/{id}', fn () => 'show');
        $r->post('/businesses/{id}/offer', fn () => 'offer');
        $this->assertSame(['id' => '12'], $r->match('GET', '/businesses/12')[1] ?? null);
        $this->assertNull($r->match('GET', '/businesses/abc'));
        $this->assertNull($r->match('GET', '/businesses/12/offer'));
        $this->assertNotNull($r->match('POST', '/businesses/12/offer'));
    }

    public function testRedirectRefusesExternalTargets(): void
    {
        $this->assertSame('/', Response::redirect('https://evil.example')->headers['Location']);
        $this->assertSame('/', Response::redirect('//evil.example')->headers['Location']);
        $this->assertSame('/businesses/3', Response::redirect('/businesses/3')->headers['Location']);
    }

    public function testSqlSplitterIgnoresComments(): void
    {
        $parts = Migrator::splitSql("-- comment;\nCREATE TABLE a (x INT);\n\nINSERT INTO a VALUES (1);\n");
        $this->assertCount(2, $parts);
        $this->assertStringStartsWith('CREATE TABLE a', $parts[0]);
    }
}
