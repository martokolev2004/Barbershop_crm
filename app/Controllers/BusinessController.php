<?php

declare(strict_types=1);

namespace BarberLead\Controllers;

use BarberLead\App;
use BarberLead\BusinessInput;
use BarberLead\Http\Request;
use BarberLead\Http\Response;
use BarberLead\Status;
use BarberLead\Support\Text;

final class BusinessController
{
    public function __construct(private App $app)
    {
    }

    public function index(Request $req): Response
    {
        $areaId = $req->int('area') ?: null;
        $area = $areaId ? $this->app->areas->find($areaId) : null;
        $areaIds = $area ? $this->app->areas->descendantIds((int) $area['id']) : null;
        $status = $req->str('status');
        $website = $req->str('web');
        $filters = [
            'q' => mb_substr($req->str('q'), 0, 100),
            'area_ids' => $areaIds ?? [],
            'status' => Status::isValid($status) ? $status : '',
            'website' => isset(Status::WEBSITE[$website]) ? $website : '',
            'sort' => $req->str('sort', 'updated'),
            'dir' => $req->str('dir', 'desc'),
            'page' => $req->int('page', 1),
        ];
        $result = $this->app->businesses->search($filters);
        return $this->app->page('businesses/index', [
            'title' => $area ? $area['name_bg'] : 'Бизнеси',
            'filters' => $filters,
            'area' => $area,
            'areaPath' => $area ? $this->app->areas->path((int) $area['id']) : [],
            'areaStats' => $area ? $this->app->businesses->stats($areaIds) : null,
            'result' => $result,
            'tree' => $this->app->areas->tree(),
            'counts' => $this->app->areas->totalCounts(),
        ]);
    }

    public function show(Request $req, int $id): Response
    {
        $b = $this->app->businesses->find($id);
        if ($b === null) {
            return $this->app->error(404, 'Няма такъв бизнес.');
        }
        $demoUrl = $this->app->demos->latestUrl($id);
        $template = (string) $this->app->settings->get('outreach_template', '');
        $outreach = strtr($template, [
            '{name}' => $b['name'],
            '{demo_url}' => $demoUrl ?? '[линк към демото]',
            '{my_name}' => (string) $this->app->settings->get('my_name', ''),
        ]);
        return $this->app->page('businesses/show', [
            'title' => $b['name'],
            'b' => $b,
            'areaLabel' => $this->app->areas->label($b['area_id'] !== null ? (int) $b['area_id'] : null),
            'events' => $this->app->events->forBusiness($id),
            'offers' => $this->app->offers->forBusiness($id),
            'demos' => $this->app->demos->forBusiness($id),
            'demoUrl' => $demoUrl,
            'outreach' => $outreach,
            'followUpDays' => (int) $this->app->settings->get('follow_up_days', '5'),
            'duplicates' => $this->app->businesses->likelyDuplicates($b['phone_normalized'], $b['website_domain'], $b['name_normalized'], $id),
        ]);
    }

    public function createForm(Request $req): Response
    {
        $prefill = ['website_status' => 'unknown', 'area_id' => $req->int('area') ?: null];
        return $this->form('Нов бизнес', $prefill, [], null);
    }

    public function create(Request $req): Response
    {
        [$data, $errors] = BusinessInput::parse($req->post, $this->app->areas->options());
        if ($errors) {
            return $this->form('Нов бизнес', $req->post, $errors, null, 422);
        }
        $dups = $this->app->businesses->likelyDuplicates($data['phone_normalized'], $data['website_domain'], $data['name_normalized']);
        if ($dups && $req->str('confirm_duplicate') !== '1') {
            return $this->form('Нов бизнес', $req->post, [], null, 409, $dups);
        }
        $id = $this->app->businesses->create($data, $this->app->actor());
        $this->app->audit->record($this->app->actor(), 'business_create', 'business', $id, [], $req->ip);
        $this->app->session->flash('ok', 'Бизнесът е добавен.');
        return Response::redirect("/businesses/$id");
    }

    public function editForm(Request $req, int $id): Response
    {
        $b = $this->app->businesses->find($id);
        if ($b === null) {
            return $this->app->error(404, 'Няма такъв бизнес.');
        }
        return $this->form('Редакция: ' . $b['name'], $b, [], $id);
    }

    public function update(Request $req, int $id): Response
    {
        if ($this->app->businesses->find($id) === null) {
            return $this->app->error(404, 'Няма такъв бизнес.');
        }
        [$data, $errors] = BusinessInput::parse($req->post, $this->app->areas->options());
        if ($errors) {
            return $this->form('Редакция', $req->post, $errors, $id, 422);
        }
        $changed = $this->app->businesses->update($id, $data, $this->app->actor());
        if ($changed) {
            $this->app->audit->record($this->app->actor(), 'business_update', 'business', $id, ['fields' => $changed], $req->ip);
        }
        $this->app->session->flash('ok', $changed ? 'Промените са записани.' : 'Нямаше промени.');
        return Response::redirect("/businesses/$id");
    }

    public function status(Request $req, int $id): Response
    {
        $status = $req->str('status');
        if (!Status::isValid($status) || $this->app->businesses->find($id) === null) {
            return $req->wantsJson ? Response::json(['error' => 'Невалиден статус.'], 422) : $this->app->error(422, 'Невалиден статус.');
        }
        $this->app->businesses->setStatus($id, $status, $this->app->actor());
        if ($req->wantsJson) {
            return Response::json(['ok' => true, 'status' => $status, 'label' => Status::label($status)]);
        }
        $this->app->session->flash('ok', 'Статус: ' . Status::label($status));
        $back = $req->str('back');
        return Response::redirect($back !== '' ? $back : "/businesses/$id");
    }

    public function note(Request $req, int $id): Response
    {
        $note = $req->str('note');
        if ($note === '' || mb_strlen($note) > 2000 || $this->app->businesses->find($id) === null) {
            $this->app->session->flash('err', 'Бележката е празна или твърде дълга.');
        } else {
            $this->app->businesses->addNote($id, $note, $this->app->actor());
        }
        return Response::redirect("/businesses/$id#history");
    }

    public function addDemo(Request $req, int $id): Response
    {
        $url = Text::normalizeUrl($req->str('url'));
        if ($url === null || $this->app->businesses->find($id) === null) {
            $this->app->session->flash('err', 'Невалиден адрес на демото.');
            return Response::redirect("/businesses/$id#demos");
        }
        $notes = mb_substr($req->str('notes'), 0, 1000);
        $this->app->demos->addManual($id, $url, $notes !== '' ? $notes : null, $this->app->actor());
        $this->app->session->flash('ok', 'Демото е добавено.');
        return Response::redirect("/businesses/$id#demos");
    }

    /**
     * @param array<string,mixed> $values
     * @param array<string,string> $errors
     * @param list<array<string,mixed>> $duplicates
     */
    private function form(string $title, array $values, array $errors, ?int $id, int $status = 200, array $duplicates = []): Response
    {
        return $this->app->page('businesses/form', [
            'title' => $title,
            'v' => $values,
            'errors' => $errors,
            'id' => $id,
            'areaOptions' => $this->app->areas->options(),
            'duplicates' => $duplicates,
        ], $status);
    }
}
