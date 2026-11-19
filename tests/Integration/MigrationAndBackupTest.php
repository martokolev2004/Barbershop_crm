<?php

declare(strict_types=1);

namespace BarberLead\Tests\Integration;

use BarberLead\Backup;
use BarberLead\Migrator;
use BarberLead\Tests\TestDatabase;
use PHPUnit\Framework\TestCase;

final class MigrationAndBackupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bl-backup-test-' . bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testMigrationsCreateSchemaAndSeedGeography(): void
    {
        $db = TestDatabase::fresh();
        $m = new Migrator($db, BL_ROOT . '/migrations');
        $this->assertSame([], $m->pending());

        $districts = $db->query("SELECT COUNT(*) FROM areas a JOIN areas c ON c.id = a.parent_id WHERE c.slug = 'sofia' AND a.type = 'district'")->fetchColumn();
        $this->assertSame(24, (int) $districts, 'Sofia has 24 administrative districts');

        $lozenets = $db->query("SELECT n.name_bg FROM areas n JOIN areas d ON d.id = n.parent_id WHERE d.slug = 'lozenets' AND n.slug = 'lozenets'")->fetchColumn();
        $this->assertSame('Лозенец', $lozenets);

        $prime = $db->query("SELECT b.name, a.name_bg FROM businesses b JOIN areas a ON a.id = b.area_id WHERE b.phone_normalized = '+359896610004'")->fetch();
        $this->assertSame(['name' => 'Prime Barbershop', 'name_bg' => 'Люлин 7'], $prime);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM demos')->fetchColumn());
    }

    public function testSeedMigrationsAreIdempotent(): void
    {
        $db = TestDatabase::fresh();
        $before = (int) $db->query('SELECT COUNT(*) FROM areas')->fetchColumn();
        \BarberLead\Seeds::seedAreas($db);
        \BarberLead\Seeds::importPrime($db);
        $this->assertSame($before, (int) $db->query('SELECT COUNT(*) FROM areas')->fetchColumn());
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM businesses')->fetchColumn());
    }

    public function testDestructiveRollbackNeedsForce(): void
    {
        $db = TestDatabase::fresh();
        $m = new Migrator($db, BL_ROOT . '/migrations');
        // 0004 and 0003 and 0002 roll back without data-loss guard; 0001 drops tables.
        $this->assertSame('0004_import_existing_demos', $m->rollback());
        $this->assertSame('0003_default_settings', $m->rollback());
        $this->assertSame('0002_seed_areas', $m->rollback());
        $this->expectException(\RuntimeException::class);
        $m->rollback();
    }

    public function testRollbackKeepsAreasThatAreInUse(): void
    {
        $db = TestDatabase::fresh();
        $m = new Migrator($db, BL_ROOT . '/migrations');
        $m->rollback(); // removes Prime import
        $m->rollback(); // settings
        $areaId = (int) $db->query("SELECT id FROM areas WHERE slug = 'strelbishte'")->fetchColumn();
        $db->prepare("INSERT INTO businesses (name, name_normalized, area_id) VALUES ('X', 'x', ?)")->execute([$areaId]);
        $m->rollback(); // seed areas: must keep Стрелбище and its parents
        $this->assertSame('Стрелбище', $db->query("SELECT name_bg FROM areas WHERE id = $areaId")->fetchColumn());
        $this->assertSame(0, (int) $db->query("SELECT COUNT(*) FROM areas WHERE slug = 'lozenets'")->fetchColumn());
        $m->migrate(); // re-seeding works and does not duplicate Стрелбище
        $this->assertSame(1, (int) $db->query("SELECT COUNT(*) FROM areas WHERE slug = 'strelbishte'")->fetchColumn());
    }

    public function testBackupRestoreRoundTripPreservesData(): void
    {
        $db = TestDatabase::fresh();
        $db->exec("INSERT INTO businesses (name, name_normalized, notes) VALUES ('O''Brien; Barbers', 'obrien', 'ред 1;\nред 2 \\\\ \"кавички\"')");
        $before = $db->query('SELECT * FROM businesses ORDER BY id')->fetchAll();
        $backup = new Backup($db, $this->dir);
        $file = $backup->create('test');
        $this->assertFileExists($file);

        $db->exec('DELETE FROM businesses');
        $backup->restore($file);
        $this->assertSame($before, $db->query('SELECT * FROM businesses ORDER BY id')->fetchAll());
        $this->assertSame(24, (int) $db->query("SELECT COUNT(*) FROM areas WHERE type = 'district'")->fetchColumn());
    }

    public function testRestoreRejectsForeignFiles(): void
    {
        mkdir($this->dir, 0700, true);
        $bad = $this->dir . '/x.sql.gz';
        file_put_contents($bad, gzencode('DROP DATABASE mysql;'));
        $this->expectException(\RuntimeException::class);
        (new Backup(TestDatabase::fresh(false), $this->dir))->restore($bad);
    }
}
