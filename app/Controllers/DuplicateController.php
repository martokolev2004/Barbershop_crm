<?php

declare(strict_types=1);

namespace BarberLead\Controllers;

use BarberLead\App;
use BarberLead\Discovery\Merger;
use BarberLead\Http\Request;
use BarberLead\Http\Response;

final class DuplicateController
{
    public function __construct(private App $app)
    {
    }

    public function index(Request $req): Response
    {
        $rows = $this->app->db->query(
            "SELECT d.*, a.name AS a_name, a.address AS a_address, a.phone AS a_phone, a.website_url AS a_web, a.instagram_url AS a_insta, a.area_id AS a_area, a.created_at AS a_created,
                    b.name AS b_name, b.address AS b_address, b.phone AS b_phone, b.website_url AS b_web, b.instagram_url AS b_insta, b.area_id AS b_area, b.created_at AS b_created
             FROM duplicate_candidates d JOIN businesses a ON a.id = d.business_id JOIN businesses b ON b.id = d.other_id
             WHERE d.status = 'open' ORDER BY d.score DESC, d.id LIMIT 200"
        )->fetchAll();
        return $this->app->page('duplicates', ['title' => 'Възможни дубликати', 'rows' => $rows]);
    }

    public function merge(Request $req, int $id): Response
    {
        $stmt = $this->app->db->prepare("SELECT * FROM duplicate_candidates WHERE id = ? AND status = 'open'");
        $stmt->execute([$id]);
        $d = $stmt->fetch();
        $keep = $req->int('keep');
        if ($d === false || !in_array($keep, [(int) $d['business_id'], (int) $d['other_id']], true)) {
            return $this->app->error(422, 'Невалидно сливане.');
        }
        $remove = $keep === (int) $d['business_id'] ? (int) $d['other_id'] : (int) $d['business_id'];
        (new Merger($this->app->db, $this->app->events))->merge($keep, $remove, $this->app->actor());
        $this->app->audit->record($this->app->actor(), 'merge', 'business', $keep, ['removed' => $remove], $req->ip);
        $this->app->session->flash('ok', 'Записите са слети.');
        return Response::redirect('/duplicates');
    }

    public function dismiss(Request $req, int $id): Response
    {
        (new Merger($this->app->db, $this->app->events))->dismiss($id);
        $this->app->session->flash('ok', 'Отбелязано като различни бизнеси.');
        return Response::redirect('/duplicates');
    }
}
