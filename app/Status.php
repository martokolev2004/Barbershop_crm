<?php

declare(strict_types=1);

namespace BarberLead;

/** Sales pipeline vocabulary (stored values are English, labels Bulgarian). */
final class Status
{
    public const LABELS = [
        'new' => 'Нов',
        'researching' => 'Проучва се',
        'researched' => 'Проучен',
        'qualified' => 'За оферта',
        'demo_generating' => 'Демо се прави',
        'demo_awaiting_approval' => 'Демо чака одобрение',
        'demo_approved' => 'Демо одобрено',
        'ready_for_outreach' => 'Готов за писане',
        'offer_sent' => 'Пусната оферта',
        'replied' => 'Разговор',
        'negotiation' => 'Преговори',
        'won' => 'Спечелен',
        'lost' => 'Загубен',
        'rejected' => 'Отхвърлен',
        'archived' => 'Архив',
    ];

    /** The 7 stages the owner works with. Other values exist for older records / later phases only. */
    public const VISIBLE = ['new', 'researching', 'qualified', 'offer_sent', 'replied', 'won', 'lost'];

    /** Kanban columns = the visible stages. */
    public const KANBAN = [
        'Нови' => ['new'],
        'Проучвам' => ['researching'],
        'За оферта' => ['qualified'],
        'Пусната оферта' => ['offer_sent'],
        'Разговор' => ['replied'],
        'Спечелени' => ['won'],
        'Загубени' => ['lost'],
    ];

    /** Website situation as the owner sees it (derived from website_url / social links / check result). */
    public const WEB_KIND = [
        'none' => 'Няма сайт',
        'social' => 'Само Instagram/FB',
        'has' => 'Има сайт',
        'unknown' => 'Непроверено',
    ];

    /** @param array<string,mixed> $b */
    public static function webKind(array $b): string
    {
        if (!empty($b['website_url'])) {
            return 'has';
        }
        if (!empty($b['instagram_url']) || !empty($b['facebook_url'])) {
            return 'social';
        }
        return ($b['website_status'] ?? '') === 'none' ? 'none' : 'unknown';
    }

    /** Stage options for a select: the visible ones, plus the current value if it is an old one. @return array<string,string> */
    public static function options(string $current = ''): array
    {
        $out = [];
        foreach (self::VISIBLE as $k) {
            $out[$k] = self::LABELS[$k];
        }
        if ($current !== '' && !isset($out[$current]) && isset(self::LABELS[$current])) {
            $out[$current] = self::LABELS[$current];
        }
        return $out;
    }

    public const CHANNELS = [
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'email' => 'Имейл',
        'phone' => 'Телефон',
        'in_person' => 'На място',
        'other' => 'Друго',
    ];

    public const WEBSITE = [
        'unknown' => 'Неизвестно',
        'none' => 'Няма открит сайт',
        'weak' => 'Слаб сайт',
        'good' => 'Добър сайт',
    ];

    public const RELEVANCE = [
        'high' => 'Бръснарница',
        'medium' => 'Салон',
        'low' => 'Неподходящ',
    ];

    public const JOB = [
        'queued' => 'На опашка',
        'running' => 'Тече',
        'done' => 'Завършено',
        'partial' => 'Частично',
        'failed' => 'Неуспешно',
        'cancelled' => 'Спряно',
    ];

    /** Hidden from the normal list, Kanban and statistics. */
    public const HIDDEN = ['archived', 'rejected'];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function isValid(string $status): bool
    {
        return isset(self::LABELS[$status]);
    }
}
