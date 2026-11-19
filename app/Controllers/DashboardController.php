<?php

declare(strict_types=1);

namespace BarberLead\Controllers;

use BarberLead\App;
use BarberLead\Http\Request;
use BarberLead\Http\Response;

final class DashboardController
{
    public function __construct(private App $app)
    {
    }

    public function index(Request $req): Response
    {
        $areas = $this->app->areas;
        // Districts of Sofia with the most businesses (only real counts; zero rows are shown too).
        $totals = $areas->totalCounts();
        $sofia = null;
        foreach ($areas->all() as $a) {
            if ($a['type'] === 'city' && $a['slug'] === 'sofia') {
                $sofia = $a;
                break;
            }
        }
        $districts = [];
        if ($sofia) {
            foreach ($areas->all() as $id => $a) {
                if ((int) $a['parent_id'] === (int) $sofia['id']) {
                    $districts[] = ['id' => $id, 'name' => $a['name_bg'], 'count' => $totals[$id] ?? 0];
                }
            }
        }
        return $this->app->page('dashboard', [
            'title' => 'Табло',
            'stats' => $this->app->businesses->stats(),
            'byStatus' => $this->app->businesses->countByStatus(),
            'due' => $this->app->offers->all('due'),
            'recent' => $this->app->events->recent(12),
            'districts' => $districts,
        ]);
    }
}
