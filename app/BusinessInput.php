<?php

declare(strict_types=1);

namespace BarberLead;

use BarberLead\Support\Input;
use BarberLead\Support\Text;

/** Validates and normalises the business edit form. */
final class BusinessInput
{
    /** Editable fields => max length (null = not a free-text field). */
    private const TEXT = [
        'name' => 200, 'brand' => 200, 'address' => 255, 'phone' => 40, 'email' => 190,
        'booking_method' => 120, 'working_hours' => 500, 'notes' => 10000,
    ];
    private const URLS = ['website_url', 'instagram_url', 'facebook_url', 'tiktok_url'];

    /**
     * @param array<string,mixed> $in
     * @param array<int,mixed> $validAreaIds keys are valid ids
     * @return array{0: array<string,mixed>, 1: array<string,string>} [data, errors]
     */
    public static function parse(array $in, array $validAreaIds): array
    {
        $data = [];
        $errors = [];

        foreach (self::TEXT as $field => $max) {
            $v = Input::str($in, $field);
            if (mb_strlen($v) > $max) {
                $errors[$field] = "Най-много $max символа.";
            }
            $data[$field] = $v === '' ? null : $v;
        }
        if ($data['name'] === null) {
            $errors['name'] = 'Името е задължително.';
        }
        if ($data['email'] !== null && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Невалиден имейл.';
        }
        $data['phone_normalized'] = Text::normalizePhone($data['phone']);
        if ($data['phone'] !== null && $data['phone_normalized'] === null) {
            $errors['phone'] = 'Не прилича на български телефонен номер.';
        }

        foreach (self::URLS as $field) {
            $raw = Input::str($in, $field);
            $url = Text::normalizeUrl($raw);
            if ($raw !== '' && $url === null) {
                $errors[$field] = 'Невалиден адрес (трябва да започва с http:// или https://).';
            }
            $data[$field] = $url;
        }
        $data['website_domain'] = Text::domain($data['website_url']);

        $area = Input::str($in, 'area_id');
        $data['area_id'] = $area === '' ? null : (int) $area;
        if ($data['area_id'] !== null && !isset($validAreaIds[$data['area_id']])) {
            $errors['area_id'] = 'Невалиден квартал.';
        }

        $rel = Input::str($in, 'relevance') ?: 'high';
        if (!in_array($rel, ['high', 'medium', 'low'], true)) {
            $errors['relevance'] = 'Невалидна стойност.';
        }
        $data['relevance'] = $rel;

        $ws = Input::str($in, 'website_status') ?: 'unknown';
        if (!isset(Status::WEBSITE[$ws])) {
            $errors['website_status'] = 'Невалидна стойност.';
        }
        $data['website_status'] = $ws;

        foreach (['lat' => [-90, 90], 'lng' => [-180, 180]] as $f => [$min, $max]) {
            $v = str_replace(',', '.', Input::str($in, $f));
            if ($v === '') {
                $data[$f] = null;
            } elseif (!is_numeric($v) || (float) $v < $min || (float) $v > $max) {
                $errors[$f] = 'Невалидна координата.';
                $data[$f] = null;
            } else {
                $data[$f] = round((float) $v, 6);
            }
        }

        $rating = str_replace(',', '.', Input::str($in, 'rating'));
        $data['rating'] = $rating === '' ? null : (float) $rating;
        if ($rating !== '' && (!is_numeric($rating) || (float) $rating < 0 || (float) $rating > 5)) {
            $errors['rating'] = 'Рейтингът е между 0 и 5.';
        }
        $reviews = Input::str($in, 'reviews_count');
        $data['reviews_count'] = $reviews === '' ? null : (int) $reviews;
        if ($reviews !== '' && !ctype_digit($reviews)) {
            $errors['reviews_count'] = 'Цяло число.';
        }
        $override = Input::str($in, 'lead_score_override');
        $data['lead_score_override'] = $override === '' ? null : (int) $override;
        if ($override !== '' && (!ctype_digit($override) || (int) $override > 100)) {
            $errors['lead_score_override'] = 'Число от 0 до 100.';
        }

        $data['name_normalized'] = Text::normalizeName((string) $data['name']);
        return [$data, $errors];
    }
}
