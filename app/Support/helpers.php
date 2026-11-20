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

/** Website situation badge: Няма сайт / Само Instagram/FB / Има сайт / Непроверено. @param array<string,mixed> $b */
function web_kind_badge(array $b): string
{
    $k = Status::webKind($b);
    $extra = $k === 'has' && in_array($b['website_status'] ?? '', ['weak', 'good'], true) ? ' · ' . Status::WEBSITE[$b['website_status']] : '';
    return '<span class="badge wk-' . e($k) . '">' . e(Status::WEB_KIND[$k] . $extra) . '</span>';
}

/** The outreach message for one business (template from Настройки). @param array<string,mixed> $b */
function outreach_text(string $template, array $b, string $myName): string
{
    return strtr($template, [
        '{name}' => (string) $b['name'],
        '{demo_url}' => (string) ($b['demo_url'] ?? '') !== '' ? (string) $b['demo_url'] : '[линк към демото]',
        '{my_name}' => $myName,
    ]);
}

/** Where to write to the business: Instagram first, then Facebook. @param array<string,mixed> $b */
function write_target(array $b): ?string
{
    return safe_href($b['instagram_url'] ?? null) ?? safe_href($b['facebook_url'] ?? null);
}

/** Small contact links (all for writing, no calls). @param array<string,mixed> $b */
function contact_links(array $b): string
{
    $out = [];
    if ($u = safe_href($b['instagram_url'] ?? null)) {
        $out[] = '<a class="chip" href="' . e($u) . '" target="_blank" rel="noopener noreferrer" title="Instagram">Instagram</a>';
    }
    if ($u = safe_href($b['facebook_url'] ?? null)) {
        $out[] = '<a class="chip" href="' . e($u) . '" target="_blank" rel="noopener noreferrer" title="Facebook">Facebook</a>';
    }
    $phone = (string) ($b['phone_normalized'] ?? '');
    if (preg_match('/^\+3598[789]\d{7}$/', $phone)) {
        $out[] = '<a class="chip" href="viber://chat?number=' . e(rawurlencode($phone)) . '" title="Viber">Viber</a>';
        $out[] = '<a class="chip" href="https://wa.me/' . e(ltrim($phone, '+')) . '" target="_blank" rel="noopener noreferrer" title="WhatsApp">WhatsApp</a>';
    }
    if (!empty($b['email'])) {
        $out[] = '<a class="chip" href="mailto:' . e($b['email']) . '" title="Имейл">Имейл</a>';
    }
    if ($b['lat'] !== null && $b['lng'] !== null) {
        $out[] = '<a class="chip" href="' . e('https://www.google.com/maps/search/?api=1&query=' . $b['lat'] . ',' . $b['lng']) . '" target="_blank" rel="noopener noreferrer" title="Карта">Карта</a>';
    }
    return $out ? '<span class="chips">' . implode('', $out) . '</span>' : '';
}

/** "Пиши": copies the message and opens Instagram/Facebook of the business. @param array<string,mixed> $b */
function write_button(array $b, string $template, string $myName, string $class = 'btn btn-sm btn-write'): string
{
    $target = write_target($b);
    return '<button type="button" class="' . e($class) . '" data-write data-message="' . e(outreach_text($template, $b, $myName)) . '"'
        . ($target ? ' data-open="' . e($target) . '"' : '') . ' title="' . ($target ? 'Копира съобщението и отваря профила' : 'Копира съобщението (няма Instagram/Facebook)') . '">Пиши</button>';
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
    $statusView = in_array($_GET['status'] ?? '', ['rejected', 'researching'], true) ? (string) $_GET['status'] : '';
    if (preg_match('/status=(\w+)/', $href, $m)) {
        return $path === '/businesses' && $statusView === $m[1];
    }
    if ($href === '/businesses' && $statusView !== '') {
        return false;
    }
    return $href === '/' ? $path === '/' : str_starts_with($path, $href);
}

/** Small POST button that changes a business status and returns to the current page. */
function status_button(int $id, string $status, string $label, string $csrf, string $class = 'btn btn-sm'): string
{
    $back = (string) ($_SERVER['REQUEST_URI'] ?? '/businesses');
    return '<form method="post" action="/businesses/' . $id . '/status" class="inline">'
        . '<input type="hidden" name="_csrf" value="' . e($csrf) . '"><input type="hidden" name="status" value="' . e($status) . '">'
        . '<input type="hidden" name="back" value="' . e($back) . '"><button class="' . e($class) . '">' . e($label) . '</button></form>';
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
        'rel' => ($filters['relevance'] ?? 'high') !== 'high' ? $filters['relevance'] : null,
        'sort' => $filters['sort'] !== 'reviews' ? $filters['sort'] : null, 'dir' => $filters['dir'] !== 'desc' ? $filters['dir'] : null,
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

function relevance_badge(string $r): string
{
    return '<span class="badge rel-' . e($r) . '">' . e(Status::RELEVANCE[$r] ?? $r) . '</span>';
}

function job_badge(string $s): string
{
    return '<span class="badge job-' . e($s) . '">' . e(Status::JOB[$s] ?? $s) . '</span>';
}

function duration(?string $from, ?string $to): string
{
    if (!$from || !$to) {
        return '—';
    }
    $sec = max(0, (int) strtotime($to) - (int) strtotime($from));
    return $sec < 60 ? $sec . ' сек' : intdiv($sec, 60) . ' мин ' . ($sec % 60) . ' сек';
}

/** "→ Салон" / "→ Бръснарница" quick move button. */
function relevance_button(int $id, string $relevance, string $label, string $csrf): string
{
    $back = (string) ($_SERVER['REQUEST_URI'] ?? '/businesses');
    return '<form method="post" action="/businesses/' . $id . '/relevance" class="inline">'
        . '<input type="hidden" name="_csrf" value="' . e($csrf) . '"><input type="hidden" name="relevance" value="' . e($relevance) . '">'
        . '<input type="hidden" name="back" value="' . e($back) . '"><button class="btn btn-sm" title="Премести">' . e($label) . '</button></form>';
}
