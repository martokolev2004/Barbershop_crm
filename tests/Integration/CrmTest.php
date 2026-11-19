<?php

declare(strict_types=1);

namespace BarberLead\Tests\Integration;

use BarberLead\Auth;
use BarberLead\BusinessInput;
use BarberLead\Http\Session;
use BarberLead\Repositories\AreaRepository;
use BarberLead\Repositories\BusinessRepository;
use BarberLead\Repositories\EventRepository;
use BarberLead\Repositories\OfferRepository;
use BarberLead\Tests\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class CrmTest extends TestCase
{
    private PDO $db;
    private BusinessRepository $biz;
    private OfferRepository $offers;
    private AreaRepository $areas;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->db = TestDatabase::fresh();
        $events = new EventRepository($this->db);
        $this->biz = new BusinessRepository($this->db, $events);
        $this->offers = new OfferRepository($this->db, $events, $this->biz);
        $this->areas = new AreaRepository($this->db);
    }

    private function areaId(string $slug, string $parentSlug): int
    {
        $stmt = $this->db->prepare('SELECT a.id FROM areas a JOIN areas p ON p.id = a.parent_id WHERE a.slug = ? AND p.slug = ?');
        $stmt->execute([$slug, $parentSlug]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,string> $in */
    private function create(array $in): int
    {
        [$data, $errors] = BusinessInput::parse($in, $this->areas->options());
        $this->assertSame([], $errors);
        return $this->biz->create($data, 'tester');
    }

    public function testCreateEditAndHistory(): void
    {
        $id = $this->create(['name' => 'Fixture Barbers', 'phone' => '0888 111 222', 'area_id' => (string) $this->areaId('lozenets', 'lozenets')]);
        [$data] = BusinessInput::parse(['name' => 'Fixture Barbers', 'phone' => '0888 111 222', 'address' => 'ул. Тестова 1',
            'area_id' => (string) $this->areaId('lozenets', 'lozenets')], $this->areas->options());
        $this->assertSame(['address'], $this->biz->update($id, $data, 'tester'));
        $this->assertSame([], $this->biz->update($id, $data, 'tester'), 'saving unchanged data records nothing');

        $types = $this->db->query("SELECT type FROM business_events WHERE business_id = $id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['created', 'edited'], $types);
    }

    public function testSearchByDistrictIncludesNeighborhoodsAndPhone(): void
    {
        $lozenets = $this->areaId('lozenets', 'sofia');
        $a = $this->create(['name' => 'Fixture A', 'phone' => '0888 111 222', 'area_id' => (string) $this->areaId('krastova-vada', 'lozenets')]);
        $this->create(['name' => 'Fixture B', 'area_id' => (string) $this->areaId('lyulin-3', 'lyulin')]);

        $ids = $this->areas->descendantIds($lozenets);
        $res = $this->biz->search(['area_ids' => $ids]);
        $this->assertSame([$a], array_map(fn ($r) => (int) $r['id'], $res['rows']));

        $this->assertSame($a, (int) $this->biz->search(['q' => '888 111'])['rows'][0]['id']);
        $this->assertSame($a, (int) $this->biz->search(['q' => 'Кръстова'])['rows'][0]['id'], 'search by neighborhood name');
        $this->assertSame(0, $this->biz->search(['q' => '%'])['total'], 'LIKE wildcards are escaped');

        $stats = $this->biz->stats($ids);
        $this->assertSame(1, $stats['total']);
    }

    public function testDuplicateDetection(): void
    {
        $id = $this->create(['name' => 'Prime Fixture Barbershop', 'phone' => '0877 000 111', 'website_url' => 'https://fixture-prime.bg']);
        $this->assertCount(1, $this->biz->likelyDuplicates('+359877000111', null, 'something else'));
        $this->assertCount(1, $this->biz->likelyDuplicates(null, 'fixture-prime.bg', 'something else'));
        $this->assertCount(1, $this->biz->likelyDuplicates(null, null, 'prime fixture'));
        $this->assertCount(0, $this->biz->likelyDuplicates('+359877000111', null, 'x', $id));
    }

    public function testMarkOfferSent(): void
    {
        $id = $this->create(['name' => 'Fixture Offer']);
        [$offerId, $errors] = $this->offers->markSent($id, [
            'channel' => 'instagram', 'amount_eur' => '250,50', 'demo_url' => 'https://fixture.testvane.org', 'note' => 'тест', 'follow_up_days' => '5',
        ], 'tester');
        $this->assertSame([], $errors);
        $offer = $this->db->query("SELECT * FROM offers WHERE id = $offerId")->fetch();
        $this->assertSame('250.50', (string) $offer['amount_eur']);
        $this->assertSame(date('Y-m-d', strtotime('+5 days')), $offer['follow_up_at']);
        $this->assertSame('offer_sent', $this->biz->find($id)['status']);
        $types = $this->db->query("SELECT type FROM business_events WHERE business_id = $id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['created', 'status', 'offer'], $types);

        // follow-up not due yet; completing it records an event exactly once
        $this->assertSame(0, $this->offers->countDueFollowUps());
        $this->assertTrue($this->offers->completeFollowUp((int) $offerId, 'tester'));
        $this->assertFalse($this->offers->completeFollowUp((int) $offerId, 'tester'));
    }

    public function testOfferValidationWritesNothing(): void
    {
        $id = $this->create(['name' => 'Fixture Invalid']);
        [$offerId, $errors] = $this->offers->markSent($id, ['channel' => 'pigeon', 'amount_eur' => 'abc', 'demo_url' => 'javascript:x'], 'tester');
        $this->assertNull($offerId);
        $this->assertArrayHasKey('channel', $errors);
        $this->assertArrayHasKey('amount_eur', $errors);
        $this->assertArrayHasKey('demo_url', $errors);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM offers')->fetchColumn());
        $this->assertSame('new', $this->biz->find($id)['status']);
    }

    public function testDueFollowUpWithZeroDaysAndNoReminder(): void
    {
        $id = $this->create(['name' => 'Fixture Due']);
        $this->offers->markSent($id, ['channel' => 'phone', 'follow_up_days' => '0'], 'tester');
        $this->assertNull($this->db->query('SELECT follow_up_at FROM offers')->fetchColumn());
        $this->db->exec("UPDATE offers SET follow_up_at = CURDATE() - INTERVAL 1 DAY");
        $this->assertSame(1, $this->offers->countDueFollowUps());
    }

    public function testAuthLoginAndLockout(): void
    {
        $auth = new Auth($this->db, new Session());
        $auth->createUser('Owner', 'correct horse battery');
        $this->assertFalse($auth->attempt('owner', 'wrong', '10.0.0.1')['ok']);
        $this->assertTrue($auth->attempt('OWNER', 'correct horse battery', '10.0.0.1')['ok']);
        $this->assertSame('owner', $auth->username());

        for ($i = 0; $i < Auth::MAX_FAILS_PER_USER; $i++) {
            $auth->attempt('owner', 'wrong', '10.0.0.' . $i);
        }
        $locked = $auth->attempt('owner', 'correct horse battery', '10.0.0.99');
        $this->assertFalse($locked['ok'], 'correct password is refused while locked out');
        $this->assertStringContainsString('Твърде много', (string) $locked['error']);
    }

    public function testPasswordChange(): void
    {
        $auth = new Auth($this->db, new Session());
        $uid = $auth->createUser('owner', 'first password 1');
        $this->assertNotNull($auth->changePassword($uid, 'wrong', 'second password 2'));
        $this->assertNotNull($auth->changePassword($uid, 'first password 1', 'short'));
        $this->assertNull($auth->changePassword($uid, 'first password 1', 'second password 2'));
        $this->assertTrue($auth->attempt('owner', 'second password 2', '1.1.1.1')['ok']);
    }
}
