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

/** Escaped form field value. @param array<string,mixed> $values */
function form_value(array $values, string $key, string $default = ''): string
{
    $v = $values[$key] ?? null;
    return e(is_scalar($v) ? (string) $v : $default);
}

/** Error message under a form field. @param array<string,string> $errors */
function form_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<span class="field-err">' . e($errors[$key]) . '</span>' : '';
}

/** Escaped value, or a grey „неизвестно“ when empty. */
function unknown_or(mixed $value): string
{
    return $value !== null && $value !== '' ? e($value) : '<span class="muted">неизвестно</span>';
}

/** External link that opens in a new tab; non-http(s) values are shown as text. */
function ext_link(?string $href, ?string $text = null): string
{
    $safe = safe_href($href);
    if ($safe === null) {
        return $href ? e($href) : '<span class="muted">—</span>';
    }
    return '<a href="' . e($safe) . '" target="_blank" rel="noopener noreferrer">' . e($text ?? $safe) . '</a>';
}

function nav_active(string $path, string $href): bool
{
    return $href === '/' ? $path === '/' : str_starts_with($path, $href);
}

/** True when the area node or anything below it is the selected area. @param array<string,mixed> $node */
function area_contains(array $node, int $selected): bool
{
    if ((int) $node['id'] === $selected) {
        return true;
    }
    foreach ($node['children'] as $child) {
        if (area_contains($child, $selected)) {
            return true;
        }
    }
    return false;
}

/**
 * Business list URL keeping the current filters.
 * @param array<string,mixed> $filters @param array<string,mixed>|null $area @param array<string,mixed> $over
 */
function biz_list_url(array $filters, ?array $area, array $over = []): string
{
    return url('/businesses', array_merge([
        'area' => $area['id'] ?? null, 'q' => $filters['q'], 'status' => $filters['status'], 'web' => $filters['website'],
        'sort' => $filters['sort'] !== 'updated' ? $filters['sort'] : null, 'dir' => $filters['dir'] !== 'desc' ? $filters['dir'] : null,
    ], $over));
}

/** Sortable column header. @param array<string,mixed> $filters @param array<string,mixed>|null $area */
function sort_link(array $filters, ?array $area, string $key, string $label): string
{
    $on = $filters['sort'] === $key;
    $dir = $on && $filters['dir'] === 'desc' ? 'asc' : 'desc';
    $arrow = $on ? ($filters['dir'] === 'asc' ? ' ↑' : ' ↓') : '';
    return '<a class="' . ($on ? 'on' : '') . '" href="' . e(biz_list_url($filters, $area, ['sort' => $key, 'dir' => $dir, 'page' => null])) . '">' . e($label) . $arrow . '</a>';
}
