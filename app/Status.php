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
        'qualified' => 'Квалифициран',
        'demo_generating' => 'Демо се прави',
        'demo_awaiting_approval' => 'Демо чака одобрение',
        'demo_approved' => 'Демо одобрено',
        'ready_for_outreach' => 'Готов за писане',
        'offer_sent' => 'Пусната оферта',
        'replied' => 'Отговориха',
        'negotiation' => 'Преговори',
        'won' => 'Спечелен',
        'lost' => 'Загубен',
        'rejected' => 'Отхвърлен',
        'archived' => 'Архив',
    ];

    /** Kanban groups: several statuses share a column to keep the board readable on a laptop. */
    public const KANBAN = [
        'Нови' => ['new', 'researching', 'researched'],
        'Квалифицирани' => ['qualified'],
        'Демо' => ['demo_generating', 'demo_awaiting_approval', 'demo_approved'],
        'За писане' => ['ready_for_outreach'],
        'Пусната оферта' => ['offer_sent'],
        'Разговор' => ['replied', 'negotiation'],
        'Спечелени' => ['won'],
        'Загубени' => ['lost'],
    ];

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
