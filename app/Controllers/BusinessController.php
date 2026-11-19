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
        $rel = $req->str('rel', 'relevant');
        $filters = [
            'q' => mb_substr($req->str('q'), 0, 100),
            'area_ids' => $areaIds ?? [],
            'status' => Status::isValid($status) ? $status : '',
            'website' => isset(Status::WEBSITE[$website]) ? $website : '',
            'relevance' => in_array($rel, ['relevant', 'high', 'medium', 'low', 'all'], true) ? $rel : 'relevant',
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
            'scans' => $area ? $this->scans((int) $area['id']) : [],
            'activeJob' => $req->int('job') ?: ($area ? $this->activeJob((int) $area['id']) : 0),
            'schedule' => $area ? $this->schedule((int) $area['id']) : null,
            'hasGoogleKey' => (bool) $this->app->settings->get('google_api_key'),
            'cityPlan' => $area && $area['type'] === 'city' ? $this->cityPlan((int) $area['id']) : null,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function scans(int $areaId): array
    {
        $stmt = $this->app->db->prepare("SELECT * FROM jobs WHERE type = 'scan' AND area_id = ? ORDER BY id DESC LIMIT 10");
        $stmt->execute([$areaId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed> scan plan of all districts of a city */
    private function cityPlan(int $cityId): array
    {
        $stmt = $this->app->db->prepare(
            "SELECT d.id, d.name_bg, s.enabled, s.interval_days, s.next_run_at,
                    (SELECT j.status FROM jobs j WHERE j.area_id = d.id ORDER BY j.id DESC LIMIT 1) AS last_status,
                    (SELECT j.finished_at FROM jobs j WHERE j.area_id = d.id ORDER BY j.id DESC LIMIT 1) AS last_finished,
                    (SELECT j.found_count FROM jobs j WHERE j.area_id = d.id ORDER BY j.id DESC LIMIT 1) AS last_found
             FROM areas d LEFT JOIN scan_schedules s ON s.area_id = d.id
             WHERE d.parent_id = ? AND d.type = 'district' ORDER BY d.sort_order, d.name_bg"
        );
        $stmt->execute([$cityId]);
        $rows = $stmt->fetchAll();
        $enabled = array_filter($rows, fn ($r) => (int) $r['enabled'] === 1);
        return [
            'districts' => $rows,
            'enabled' => count($enabled),
            'interval' => $enabled ? (int) reset($enabled)['interval_days'] : 15,
        ];
    }

    private function activeJob(int $areaId): int
    {
        $stmt = $this->app->db->prepare("SELECT id FROM jobs WHERE area_id = ? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$areaId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    private function schedule(int $areaId): ?array
    {
        $stmt = $this->app->db->prepare('SELECT * FROM scan_schedules WHERE area_id = ?');
        $stmt->execute([$areaId]);
        $r = $stmt->fetch();
        return $r === false ? null : $r;
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
            'sources' => $this->sources($id),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function sources(int $id): array
    {
        $stmt = $this->app->db->prepare('SELECT * FROM business_sources WHERE business_id = ? ORDER BY last_seen_at DESC');
        $stmt->execute([$id]);
        return $stmt->fetchAll();
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
