<?php

declare(strict_types=1);

namespace BarberLead\Controllers;

use BarberLead\App;
use BarberLead\Http\Request;
use BarberLead\Http\Response;

final class OfferController
{
    public function __construct(private App $app)
    {
    }

    public function index(Request $req): Response
    {
        $filter = in_array($req->str('f'), ['due', 'open'], true) ? $req->str('f') : 'all';
        return $this->app->page('offers', [
            'title' => 'Оферти',
            'filter' => $filter,
            'offers' => $this->app->offers->all($filter),
        ]);
    }

    /** "Пусната оферта" – records the offer; never sends anything. */
    public function markSent(Request $req, int $id): Response
    {
        $days = (int) $this->app->settings->get('follow_up_days', '5');
        [$offerId, $errors] = $this->app->offers->markSent($id, $req->post, $this->app->actor(), $days);
        if ($offerId === null) {
            $this->app->session->flash('err', 'Офертата не е записана: ' . implode(' ', $errors));
            return Response::redirect("/businesses/$id#offer");
        }
        $this->app->audit->record($this->app->actor(), 'offer_sent', 'business', $id, ['offer_id' => $offerId], $req->ip);
        $this->app->session->flash('ok', 'Записах, че офертата е пусната.');
        return Response::redirect("/businesses/$id");
    }

    public function followUpDone(Request $req, int $id): Response
    {
        $this->app->offers->completeFollowUp($id, $this->app->actor());
        $back = $req->str('back');
        return Response::redirect($back !== '' ? $back : '/offers');
    }
}
