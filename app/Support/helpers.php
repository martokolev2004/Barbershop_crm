<?php

declare(strict_types=1);

use BarberLead\Status;

/** HTML-escape. Every value printed in a template goes through this. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Builds a URL with query parameters, dropping empty ones. @param array<string,mixed> $params */
function url(string $path, array $params = []): string
{
    $params = array_filter($params, fn ($v) => $v !== null && $v !== '' && $v !== 0);
    return $path . ($params ? '?' . http_build_query($params) : '');
}

function status_badge(string $status): string
{
    return '<span class="badge st-' . e($status) . '">' . e(Status::label($status)) . '</span>';
}

function website_badge(string $ws): string
{
    return '<span class="badge web-' . e($ws) . '">' . e(Status::WEBSITE[$ws] ?? $ws) . '</span>';
}

function fmt_date(?string $dt, bool $time = false): string
{
    if ($dt === null || $dt === '') {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts === false ? e($dt) : date($time ? 'd.m.Y H:i' : 'd.m.Y', $ts);
}

/** Only http(s) links are rendered as clickable (blocks javascript: URLs stored in records). */
function safe_href(?string $u): ?string
{
    if ($u === null || $u === '') {
        return null;
    }
    return preg_match('~^https?://~i', $u) ? $u : null;
}
