<?php

declare(strict_types=1);

namespace BarberLead\Repositories;

use PDO;

final class AreaRepository
{
    /** @var array<int,array<string,mixed>>|null */
    private ?array $all = null;

    public function __construct(private PDO $db)
    {
    }

    /** @return array<int,array<string,mixed>> id => row */
    public function all(): array
    {
        if ($this->all === null) {
            $this->all = [];
            foreach ($this->db->query('SELECT * FROM areas ORDER BY sort_order, name_bg') as $row) {
                $this->all[(int) $row['id']] = $row;
            }
        }
        return $this->all;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /** @return list<array<string,mixed>> nested tree: each node has 'children' */
    public function tree(): array
    {
        $byParent = [];
        foreach ($this->all() as $row) {
            $byParent[(int) ($row['parent_id'] ?? 0)][] = $row;
        }
        return $this->buildTree($byParent, 0);
    }

    /**
     * @param array<int,list<array<string,mixed>>> $byParent
     * @return list<array<string,mixed>>
     */
    private function buildTree(array $byParent, int $parent): array
    {
        $out = [];
        foreach ($byParent[$parent] ?? [] as $row) {
            $row['children'] = $this->buildTree($byParent, (int) $row['id']);
            $out[] = $row;
        }
        return $out;
    }

    /** @return list<int> the area itself and all areas below it */
    public function descendantIds(int $id): array
    {
        $children = [];
        foreach ($this->all() as $row) {
            $children[(int) ($row['parent_id'] ?? 0)][] = (int) $row['id'];
        }
        $out = [];
        $stack = [$id];
        while ($stack) {
            $cur = array_pop($stack);
            $out[] = $cur;
            foreach ($children[$cur] ?? [] as $c) {
                $stack[] = $c;
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> root → area (for breadcrumbs and full labels) */
    public function path(int $id): array
    {
        $path = [];
        $cur = $this->find($id);
        while ($cur !== null) {
            array_unshift($path, $cur);
            $cur = $cur['parent_id'] !== null ? $this->find((int) $cur['parent_id']) : null;
        }
        return $path;
    }

    /** "Лозенец, Лозенец, София" style label without the country. */
    public function label(?int $id): string
    {
        if ($id === null) {
            return '—';
        }
        $names = array_map(fn ($a) => $a['name_bg'], array_filter($this->path($id), fn ($a) => $a['type'] !== 'country'));
        return implode(' · ', $names);
    }

    /** @return array<int,int> area id => number of businesses directly in that area */
    public function directCounts(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT area_id, COUNT(*) c FROM businesses WHERE area_id IS NOT NULL AND status <> 'archived' GROUP BY area_id") as $r) {
            $out[(int) $r['area_id']] = (int) $r['c'];
        }
        return $out;
    }

    /** @return array<int,int> area id => businesses in the area or below it */
    public function totalCounts(): array
    {
        $direct = $this->directCounts();
        $totals = [];
        foreach ($this->all() as $id => $row) {
            $sum = 0;
            foreach ($this->descendantIds($id) as $d) {
                $sum += $direct[$d] ?? 0;
            }
            $totals[$id] = $sum;
        }
        return $totals;
    }

    /** Areas a business can be placed in, as [id => "label"] for a <select>. @return array<int,string> */
    public function options(): array
    {
        $out = [];
        foreach ($this->all() as $id => $row) {
            if ($row['type'] === 'country') {
                continue;
            }
            $out[$id] = $this->label($id);
        }
        asort($out, SORT_LOCALE_STRING);
        return $out;
    }
}
