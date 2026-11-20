<?php

declare(strict_types=1);

namespace BarberLead\Controllers;

use BarberLead\App;
use BarberLead\Discovery\AreaLocator;
use BarberLead\Discovery\Candidate;
use BarberLead\Discovery\GooglePlacesSource;
use BarberLead\Discovery\Importer;
use BarberLead\Discovery\JobRunner;
use BarberLead\Discovery\Relevance;
use BarberLead\Http\Request;
use BarberLead\Http\Response;

/**
 * "Търси": a business the owner saw somewhere. First our own base (every status, rejected too),
 * then, on request, one Google Places lookup by name; a result can be added with one click.
 */
final class SearchController
{
    private const SESSION_KEY = 'google_lookup';

    public function __construct(private App $app)
    {
    }

    public function index(Request $req): Response
    {
        $q = $this->query($req->str('q'));
        $ours = $q === '' ? [] : $this->app->businesses->search(['q' => $q, 'status' => 'any', 'relevance' => 'all', 'per_page' => 50])['rows'];
        $lookup = $this->app->session->get(self::SESSION_KEY);
        $google = is_array($lookup) && ($lookup['q'] ?? null) === $q ? $this->withMatches($lookup['items'] ?? []) : null;
        return $this->app->page('search', [
            'title' => $q !== '' ? 'Търсене: ' . $q : 'Търсене',
            'q' => $q,
            'ours' => $ours,
            'google' => $google,
            'hasGoogleKey' => (bool) $this->app->settings->get('google_api_key'),
            'template' => (string) $this->app->settings->get('outreach_template', ''),
            'myName' => (string) $this->app->settings->get('my_name', ''),
        ]);
    }

    public function google(Request $req): Response
    {
        $q = $this->query($req->str('q'));
        $back = url('/search', ['q' => $q]);
        $key = (string) $this->app->settings->get('google_api_key', '');
        if ($q === '' || $key === '') {
            $this->app->session->flash('err', $key === '' ? 'Няма Google ключ (Настройки).' : 'Напиши име.');
            return Response::redirect($back);
        }
        $runner = new JobRunner($this->app->db, $this->app->events, $this->app->settings, $this->app->http);
        if (!$runner->googleBudgetLeft()) {
            $this->app->session->flash('err', 'Месечният лимит за Google заявки е изчерпан (Настройки).');
            return Response::redirect($back);
        }
        $runner->countGoogleRequest();
        $res = (new GooglePlacesSource($this->app->http, $key))->findByName($q);
        if ($res->error !== null) {
            $this->app->session->flash('err', $res->error);
            return Response::redirect($back);
        }
        $this->app->session->set(self::SESSION_KEY, [
            'q' => $q,
            'items' => array_map(fn (Candidate $c) => ['id' => $c->sourceId, 'url' => $c->url] + $c->toArray(), $res->candidates),
        ]);
        $this->app->audit->record($this->app->actor(), 'google_lookup', 'search', null, ['q' => $q, 'found' => count($res->candidates)], $req->ip);
        return Response::redirect($back . '#google');
    }

    public function add(Request $req): Response
    {
        $lookup = $this->app->session->get(self::SESSION_KEY);
        $item = null;
        foreach (is_array($lookup) ? ($lookup['items'] ?? []) : [] as $i) {
            if (($i['id'] ?? null) === $req->str('place')) {
                $item = $i;
            }
        }
        if ($item === null) {
            $this->app->session->flash('err', 'Резултатът от Google е изтекъл. Потърси пак.');
            return Response::redirect('/search');
        }
        $importer = new Importer($this->app->db, $this->app->events, new AreaLocator($this->app->db));
        $r = $importer->import(self::candidate($item), null, 'ръчно търсене');
        $this->app->audit->record($this->app->actor(), 'google_add', 'business', $r['business_id'], ['action' => $r['action']], $req->ip);
        $this->app->session->flash('ok', match ($r['action']) {
            'new' => 'Добавен от Google.',
            'review' => 'Добавен. Прилича на бизнес, който вече имаш – виж „Дубликати“.',
            default => 'Вече го имаше – данните са допълнени от Google.',
        });
        return Response::redirect('/businesses/' . $r['business_id']);
    }

    private function query(string $q): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $q) ?? ''), 0, 100);
    }

    /** @param array<string,mixed> $i stored lookup item */
    public static function candidate(array $i): Candidate
    {
        return new Candidate(
            source: 'google',
            sourceId: (string) $i['id'],
            name: (string) ($i['name'] ?? ''),
            lat: isset($i['lat']) ? (float) $i['lat'] : null,
            lng: isset($i['lng']) ? (float) $i['lng'] : null,
            address: $i['address'] ?? null,
            phone: $i['phone'] ?? null,
            website: $i['website'] ?? null,
            instagram: $i['instagram'] ?? null,
            facebook: $i['facebook'] ?? null,
            openingHours: $i['opening_hours'] ?? null,
            rating: isset($i['rating']) ? (float) $i['rating'] : null,
            reviewsCount: isset($i['reviews_count']) ? (int) $i['reviews_count'] : null,
            url: $i['url'] ?? null,
            types: is_array($i['types'] ?? null) ? array_values(array_map('strval', $i['types'])) : [],
            closed: !empty($i['closed']),
        );
    }

    /**
     * Marks which Google results we already have.
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function withMatches(array $items): array
    {
        $importer = new Importer($this->app->db, $this->app->events);
        $known = $this->app->db->prepare("SELECT business_id FROM business_sources WHERE source = 'google' AND source_id = ?");
        $out = [];
        foreach ($items as $i) {
            $c = self::candidate($i);
            $known->execute([$c->sourceId]);
            $id = $known->fetchColumn();
            if ($id === false) {
                [$best, $score] = $importer->bestMatch($c);
                $id = $best !== null && $score >= Importer::AUTO_MATCH ? $best : false;
            }
            $i['business_id'] = $id === false ? null : (int) $id;
            $i['relevance'] = Relevance::classify($c)[0];
            $out[] = $i;
        }
        return $out;
    }
}
