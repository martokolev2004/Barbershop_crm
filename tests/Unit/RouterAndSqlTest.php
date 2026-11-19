<?php

declare(strict_types=1);

namespace BarberLead\Tests\Unit;

use BarberLead\Http\Response;
use BarberLead\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterAndSqlTest extends TestCase
{
    public function testRouterMatchesNumericParamsOnly(): void
    {
        $r = new Router();
        $r->get('/businesses/{id}', 'biz.show');
        $r->post('/businesses/{id}/offer', 'offer.sent');
        $r->get('/businesses', 'biz.index');
        $this->assertSame(['biz.show', 12], $r->match('GET', '/businesses/12'));
        $this->assertSame(['biz.index', 0], $r->match('GET', '/businesses'));
        $this->assertNull($r->match('GET', '/businesses/abc'));
        $this->assertNull($r->match('GET', '/businesses/12/offer'));
        $this->assertSame(['offer.sent', 12], $r->match('POST', '/businesses/12/offer'));
    }

    public function testRedirectRefusesExternalTargets(): void
    {
        $this->assertSame('/', Response::redirect('https://evil.example')->headers['Location']);
        $this->assertSame('/', Response::redirect('//evil.example')->headers['Location']);
        $this->assertSame('/businesses/3', Response::redirect('/businesses/3')->headers['Location']);
    }

    public function testMigrationFilesAreDataOnly(): void
    {
        foreach (glob(BL_ROOT . '/migrations/*.php') ?: [] as $file) {
            $data = require $file;
            $this->assertIsArray($data, basename($file));
            $this->assertArrayHasKey('up', $data);
            $this->assertArrayHasKey('down', $data);
        }
    }

    /** The hosting deploy checker refuses dynamic calls like $fn() and .sql/.hash files. */
    public function testDeployableCodeHasNoVariableFunctionCalls(): void
    {
        $offenders = [];
        foreach (['app', 'public', 'views', 'migrations', 'data', 'bin'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BL_ROOT . '/' . $dir));
            foreach ($it as $f) {
                if ($f->isFile() && preg_match('/\.(sql|hash)$/', $f->getFilename())) {
                    $offenders[] = $f->getPathname();
                }
                if (!$f->isFile() || $f->getExtension() !== 'php' || $f->getFilename() === 'dev-router.php') {
                    continue;
                }
                $prev = null;
                foreach (token_get_all((string) file_get_contents($f->getPathname())) as $tok) {
                    if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if ($tok === '(' && is_array($prev) && $prev[0] === T_VARIABLE) {
                        $offenders[] = $f->getPathname() . ':' . $prev[2];
                    }
                    if ($tok === '(' && $prev === ')') {
                        // ($expr)(...) style call
                    }
                    $prev = $tok;
                }
            }
        }
        $this->assertSame([], $offenders);
    }

    /** The checker also refuses e.g. 'system' passed as an argument (it looks like a callback). */
    public function testNoDangerousFunctionNamesAsStringArguments(): void
    {
        $bad = [];
        foreach (['app', 'public', 'views', 'migrations', 'data', 'bin'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BL_ROOT . '/' . $dir));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php'
                    && preg_match("/[(,]\\s*'(system|exec|shell_exec|passthru|popen|proc_open|eval|assert)'/", (string) file_get_contents($f->getPathname()))) {
                    $bad[] = $f->getPathname();
                }
            }
        }
        $this->assertSame([], $bad);
    }
}
