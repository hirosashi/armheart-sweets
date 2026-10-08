<?php
namespace App\Services;

use App\Core\Clock;

/**
 * 左メニューの「発注ごと」ツリー。
 *   発注（案件） → 作る商品 → 部位（仕込み） → 原料
 *                         → 資材（商品に直接使う。仕上げ日に使う）
 * 材料の判定は Allocation::materials()（納品日の早い発注から在庫・納品待ちを割り当てた結果）。
 * 足りている材料は1行にまとめ、不足・納品が遅い・発注済のものだけを個別に出す。
 */
class JobTree
{
    public const STATE_LABELS = [
        'todo'  => '予定',
        'doing' => '仕込み中',
        'done'  => 'できあがり',
        'late'  => '遅れ',
    ];

    public const LIMIT = 30;

    public static function build(): array
    {
        $jobs = Jobs::open(self::LIMIT);
        if ($jobs === []) {
            return [];
        }
        $ids   = array_map(fn($j) => (int)$j['id'], $jobs);
        $items = Jobs::items($ids);
        $rows  = Jobs::partRows($ids);
        $alloc = Allocation::materials();
        $today = Clock::today();

        $byRow  = [];
        $byItem = [];
        foreach ($alloc['needs'] as $n) {
            if ($n['row_id'] !== null) {
                $byRow[$n['row_id']][] = $n;
            } else {
                $byItem[$n['item_id']][] = $n;
            }
        }

        $out = [];
        foreach ($jobs as $j) {
            $jid     = (int)$j['id'];
            $jobRows = $rows[$jid] ?? [];
            $alerts  = [];
            $itemOut = [];
            foreach ($items[$jid] ?? [] as $it) {
                $iid    = (int)$it['id'];
                $groups = [];
                foreach ($jobRows as $r) {
                    if ((int)$r['job_item_id'] !== $iid) {
                        continue;
                    }
                    $pid = (int)$r['part_id'];
                    $groups[$pid]['name'] = $r['part_name'];
                    $groups[$pid]['rows'][] = $r;
                }
                $parts = [];
                foreach ($groups as $pid => $g) {
                    $needs = [];
                    foreach ($g['rows'] as $r) {
                        foreach ($byRow[(int)$r['id']] ?? [] as $n) {
                            $needs[] = $n;
                        }
                    }
                    $mats = self::mergeMaterials($needs, $alloc['materials']);
                    foreach ($mats['alert'] as $m) {
                        if ($m['judge'] === 'short' || $m['judge'] === 'late') {
                            $alerts[$m['material_id']] = true;
                        }
                    }
                    $parts[] = [
                        'part_id' => $pid,
                        'name'    => $g['name'],
                        'batches' => array_sum(array_map(fn($r) => (float)$r['batches'], $g['rows'])),
                        'done'    => array_sum(array_map(fn($r) => (float)$r['done_qty'], $g['rows'])),
                        'first'   => self::firstOpenDate($g['rows']),
                        'state'   => self::rowsState($g['rows'], $today),
                        'alert'   => $mats['alert'],
                        'ok'      => $mats['ok'],
                    ];
                }
                $supplies = self::mergeMaterials($byItem[$iid] ?? [], $alloc['materials']);
                foreach ($supplies['alert'] as $m) {
                    if ($m['judge'] === 'short' || $m['judge'] === 'late') {
                        $alerts[$m['material_id']] = true;
                    }
                }
                $itemRows = array_values(array_filter($jobRows, fn($r) => (int)$r['job_item_id'] === $iid));
                $itemOut[] = [
                    'id'           => $iid,
                    'product_id'   => (int)$it['product_id'],
                    'product_name' => $it['product_name'],
                    'qty'          => (int)$it['qty'],
                    'finish_date'  => $it['finish_date'],
                    'state'        => self::rowsState($itemRows, $today),
                    'ach'          => Jobs::achievement($itemRows),
                    'parts'        => $parts,
                    'supplies'     => $supplies,
                ];
            }
            $out[] = [
                'id'            => $jid,
                'label'         => Jobs::label($j),
                'delivery_date' => $j['delivery_date'],
                'late'          => $j['delivery_date'] < $today,
                'ach'           => Jobs::achievement($jobRows),
                'short'         => count($alerts),
                'items'         => $itemOut,
            ];
        }
        return $out;
    }

    /** 部位の仕込み行の状態（全部できあがり → できあがり／過ぎて残りあり → 遅れ／一部でも進んだ → 仕込み中） */
    private static function rowsState(array $rows, string $today): string
    {
        if ($rows === []) {
            return 'todo';
        }
        $all = true;
        $any = false;
        $late = false;
        foreach ($rows as $r) {
            if ($r['status'] !== 'done') {
                $all = false;
                if ($r['target_date'] < $today) {
                    $late = true;
                }
            }
            if ($r['status'] !== 'todo') {
                $any = true;
            }
        }
        if ($all) {
            return 'done';
        }
        return $late ? 'late' : ($any ? 'doing' : 'todo');
    }

    /** できあがっていない最初の仕込み日（全部できあがりなら最初の日） */
    private static function firstOpenDate(array $rows): string
    {
        $dates = array_map(fn($r) => $r['target_date'], array_filter($rows, fn($r) => $r['status'] !== 'done'));
        if ($dates === []) {
            $dates = array_map(fn($r) => $r['target_date'], $rows);
        }
        return min($dates);
    }

    /**
     * 材料ごとにまとめ、気をつけるもの（不足・納品が遅い・発注済）と足りているものに分ける。
     * @return array{alert: list<array>, ok: list<array>}
     */
    private static function mergeMaterials(array $needs, array $materials): array
    {
        $m = [];
        foreach ($needs as $n) {
            $mid = $n['material_id'];
            if (!isset($m[$mid])) {
                $m[$mid] = [
                    'material_id' => $mid,
                    'name'        => $materials[$mid]['name'] ?? '（削除された材料）',
                    'unit'        => $materials[$mid]['unit'] ?? '',
                    'qty'         => 0.0,
                    'short'       => 0.0,
                    'judge'       => $n['judge'],
                    'use_date'    => $n['use_date'],
                    'arrival'     => $n['arrival'],
                ];
            }
            $m[$mid]['qty']   += $n['qty'];
            $m[$mid]['short'] += $n['short'];
            $m[$mid]['judge']  = Allocation::worse($m[$mid]['judge'], $n['judge']);
            $m[$mid]['use_date'] = min($m[$mid]['use_date'], $n['use_date']);
            if ($n['arrival'] !== null && ($m[$mid]['arrival'] === null || $n['arrival'] > $m[$mid]['arrival'])) {
                $m[$mid]['arrival'] = $n['arrival'];
            }
        }
        $rank  = ['short' => 0, 'late' => 1, 'ordered' => 2];
        $alert = array_values(array_filter($m, fn($x) => isset($rank[$x['judge']])));
        usort($alert, fn($a, $b) => [$rank[$a['judge']], $a['name']] <=> [$rank[$b['judge']], $b['name']]);
        $ok = array_values(array_filter($m, fn($x) => !isset($rank[$x['judge']])));
        usort($ok, fn($a, $b) => strcmp($a['name'], $b['name']));
        return ['alert' => $alert, 'ok' => $ok];
    }
}
