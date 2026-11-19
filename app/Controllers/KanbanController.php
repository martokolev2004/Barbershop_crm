<?php

declare(strict_types=1);

namespace BarberLead\Controllers;

use BarberLead\App;
use BarberLead\Http\Request;
use BarberLead\Http\Response;
use BarberLead\Status;

final class KanbanController
{
    public function __construct(private App $app)
    {
    }

    public function index(Request $req): Response
    {
        $statuses = array_merge(...array_values(Status::KANBAN));
        $rows = $this->app->businesses->byStatuses($statuses, 100);
        $columns = [];
        foreach (Status::KANBAN as $title => $group) {
            $columns[$title] = ['statuses' => $group, 'cards' => []];
        }
        foreach ($rows as $row) {
            foreach ($columns as $title => $col) {
                if (in_array($row['status'], $col['statuses'], true)) {
                    $row['area_label'] = $this->app->areas->label($row['area_id'] !== null ? (int) $row['area_id'] : null);
                    $columns[$title]['cards'][] = $row;
                    break;
                }
            }
        }
        return $this->app->page('kanban', ['title' => 'Kanban', 'columns' => $columns]);
    }
}
