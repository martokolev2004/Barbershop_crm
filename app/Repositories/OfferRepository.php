<?php

declare(strict_types=1);

namespace BarberLead\Repositories;

use BarberLead\Status;
use BarberLead\Support\Input;
use BarberLead\Support\Text;
use PDO;

/**
 * "Пусната оферта" bookkeeping. This class never contacts anybody: it only records that the
 * owner sent an offer himself, through the channel he chose.
 */
final class OfferRepository
{
    public function __construct(private PDO $db, private EventRepository $events, private BusinessRepository $businesses)
    {
    }

    /**
     * @param array<string,mixed> $in raw form input
     * @return array{0: ?int, 1: array<string,string>} [offer id, errors]
     */
    public function markSent(int $businessId, array $in, string $actor, int $defaultFollowUpDays = 5): array
    {
        $errors = [];

        $channel = Input::str($in, 'channel');
        if (!isset(Status::CHANNELS[$channel])) {
            $errors['channel'] = 'Избери канал.';
        }
        $amountRaw = str_replace([',', ' '], ['.', ''], Input::str($in, 'amount_eur'));
        $amount = null;
        if ($amountRaw !== '') {
            if (!is_numeric($amountRaw) || (float) $amountRaw < 0 || (float) $amountRaw > 1000000) {
                $errors['amount_eur'] = 'Невалидна сума.';
            } else {
                $amount = round((float) $amountRaw, 2);
            }
        }
        $demoUrl = Text::normalizeUrl(Input::str($in, 'demo_url'));
        if (Input::str($in, 'demo_url') !== '' && $demoUrl === null) {
            $errors['demo_url'] = 'Невалиден адрес.';
        }
        $note = Input::str($in, 'note');
        if (mb_strlen($note) > 5000) {
            $errors['note'] = 'Твърде дълга бележка.';
        }
        $followRaw = Input::str($in, 'follow_up_days');
        $followDays = $followRaw === '' ? $defaultFollowUpDays : (int) $followRaw;
        if ($followRaw !== '' && (!ctype_digit($followRaw) || $followDays > 365)) {
            $errors['follow_up_days'] = 'Брой дни между 0 и 365.';
        }
        if ($errors || $this->businesses->find($businessId) === null) {
            return [null, $errors ?: ['business' => 'Няма такъв бизнес.']];
        }

        $followUp = $followDays > 0 ? date('Y-m-d', strtotime("+$followDays days")) : null;
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO offers (business_id, sent_at, channel, amount_eur, demo_url, note, follow_up_at) VALUES (?, NOW(), ?, ?, ?, ?, ?)'
            )->execute([$businessId, $channel, $amount, $demoUrl, $note !== '' ? $note : null, $followUp]);
            $offerId = (int) $this->db->lastInsertId();
            $this->businesses->setStatus($businessId, 'offer_sent', $actor);
            $msg = 'Пусната оферта през ' . Status::CHANNELS[$channel]
                . ($amount !== null ? ', ' . number_format($amount, 2, ',', ' ') . ' €' : '')
                . ($followUp ? ', напомняне на ' . date('d.m.Y', (int) strtotime($followUp)) : '');
            $this->events->add($businessId, 'offer', $msg, $actor, ['offer_id' => $offerId, 'demo_url' => $demoUrl]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return [$offerId, []];
    }

    /** @return list<array<string,mixed>> */
    public function forBusiness(int $businessId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM offers WHERE business_id = ? ORDER BY sent_at DESC, id DESC');
        $stmt->execute([$businessId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function all(?string $filter = null): array
    {
        $where = match ($filter) {
            'due' => 'o.follow_up_at IS NOT NULL AND o.follow_up_done_at IS NULL AND o.follow_up_at <= CURDATE()',
            'open' => 'o.follow_up_done_at IS NULL',
            default => '1=1',
        };
        return $this->db->query(
            "SELECT o.*, b.name AS business_name, b.status AS business_status, b.phone, b.instagram_url
             FROM offers o JOIN businesses b ON b.id = o.business_id WHERE $where ORDER BY o.sent_at DESC, o.id DESC LIMIT 500"
        )->fetchAll();
    }

    public function countDueFollowUps(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM offers WHERE follow_up_at IS NOT NULL AND follow_up_done_at IS NULL AND follow_up_at <= CURDATE()'
        )->fetchColumn();
    }

    public function completeFollowUp(int $offerId, string $actor): bool
    {
        $stmt = $this->db->prepare('SELECT business_id FROM offers WHERE id = ? AND follow_up_done_at IS NULL');
        $stmt->execute([$offerId]);
        $bid = $stmt->fetchColumn();
        if ($bid === false) {
            return false;
        }
        $this->db->prepare('UPDATE offers SET follow_up_done_at = NOW() WHERE id = ?')->execute([$offerId]);
        $this->events->add((int) $bid, 'follow_up', 'Напомнянето е отметнато като направено', $actor, ['offer_id' => $offerId]);
        return true;
    }
}
