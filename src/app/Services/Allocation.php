<?php
namespace App\Services;

use App\Core\Clock;
use App\Core\Db;

/**
 * 発注（案件）への割り当て。同じ部位・同じ材料を複数の発注で使うときは、納品日の早い発注から順に割り当てる。
 *   部位 … 進み具合（日×部位）のできた回数を、その日までに仕込む予定の行へ納品日順に割り当てる。
 *          その日を「できあがり」にすると、その日までの行はすべてできあがり（Progress の引き継ぎと同じ考え方）。
 *   材料 … 今ある在庫 → 発注済みで納品待ちの分（納品予定日順）を、進行中の発注の使う予定へ納品日順に割り当てる。
 *          できあがりの部位は在庫から引き済みなので数えない。商品に直接使う資材は仕上げ日に使う。
 */
class Allocation
{
    public const JUDGE_LABELS = [
        'short'   => '不足',
        'late'    => '納品が遅い',
        'ordered' => '発注済',
        'ok'      => '足りる',
        'exempt'  => '在庫管理なし',
    ];
    private const JUDGE_RANK = ['exempt' => 0, 'ok' => 1, 'ordered' => 2, 'late' => 3, 'short' => 4];
    private const EPS = 0.0005;

    private static ?array $materialsCache = null;

    /** 2つの判定のうち悪いほう */
    public static function worse(string $a, string $b): string
    {
        return self::JUDGE_RANK[$a] >= self::JUDGE_RANK[$b] ? $a : $b;
    }

    /**
     * 部位の仕込み行ごとの進み具合。job_part_id => ['status' => todo|doing|done, 'done' => できた回数]
     * $partIds が null なら全部位。
     */
    public static function partProgress(?array $partIds = null): array
    {
        if ($partIds === []) {
            return [];
        }
        $w = '';
        $p = [];
        if ($partIds !== null) {
            $w = ' AND part_id IN (' . implode(',', array_fill(0, count($partIds), '?')) . ')';
            $p = array_map('intval', $partIds);
        }
        $rowsByPart = [];
        foreach (Db::all(
            "SELECT jp.id, jp.part_id, jp.target_date, jp.batches, j.delivery_date, j.status AS job_status
               FROM job_parts jp JOIN jobs j ON j.id = jp.job_id
              WHERE j.status IN ('open','done') " . str_replace('part_id', 'jp.part_id', $w) . '
              ORDER BY jp.part_id, jp.target_date, j.delivery_date, jp.id',
            $p
        ) as $r) {
            $rowsByPart[(int)$r['part_id']][] = $r;
        }
        $progByPart = [];
        foreach (Db::all(
            'SELECT part_id, target_date, done_qty, status FROM part_progress WHERE 1 = 1' . $w . ' ORDER BY part_id, target_date',
            $p
        ) as $r) {
            $progByPart[(int)$r['part_id']][] = $r;
        }

        $out = [];
        foreach ($rowsByPart as $pid => $list) {
            foreach ($list as $r) {
                $out[(int)$r['id']] = ['status' => 'todo', 'done' => 0.0];
            }
            $queue = [];
            $i = 0;
            $n = count($list);
            foreach ($progByPart[$pid] ?? [] as $pg) {
                while ($i < $n && $list[$i]['target_date'] <= $pg['target_date']) {
                    $queue[] = $list[$i];
                    $i++;
                }
                usort($queue, static fn($a, $b) => [$a['delivery_date'], $a['target_date'], (int)$a['id']]
                                               <=> [$b['delivery_date'], $b['target_date'], (int)$b['id']]);
                $left = (float)$pg['done_qty'];
                foreach ($queue as $r) {
                    $id   = (int)$r['id'];
                    $take = min(max(0.0, (float)$r['batches'] - $out[$id]['done']), $left);
                    if ($take > 0) {
                        $out[$id]['done'] += $take;
                        $left -= $take;
                    }
                }
                $keep = [];
                foreach ($queue as $r) {
                    $id = (int)$r['id'];
                    if ($pg['status'] === 'done' || $out[$id]['done'] + self::EPS >= (float)$r['batches']) {
                        $out[$id]['status'] = 'done';
                        continue;
                    }
                    if ($pg['status'] === 'doing' || $out[$id]['done'] > 0) {
                        $out[$id]['status'] = 'doing';
                    }
                    $keep[] = $r;
                }
                $queue = $keep;
            }
            foreach ($list as $r) {
                if ($r['job_status'] === 'done') {
                    $out[(int)$r['id']]['status'] = 'done';
                }
            }
        }
        return $out;
    }

    /**
     * 進行中の発注の材料を、納品日の早い発注から順に「今ある在庫 → 納品待ち（納品予定日順）」へ割り当てる。
     * @return array{needs: list<array>, materials: array<int, array>, stock: array<int, float>, incoming: array<int, list<array>>}
     *   needs の各行: job_id, item_id, row_id（部位の仕込み行。資材は null）, part_id, material_id, use_date, delivery_date,
     *                 qty, from_stock, from_po, short, arrival（納品待ちから当てた分の最も遅い納品予定日）, judge
     */
    public static function materials(): array
    {
        if (self::$materialsCache !== null) {
            return self::$materialsCache;
        }
        $progress = self::partProgress();
        $needs = [];
        foreach (Db::all(
            "SELECT jp.id AS row_id, jp.job_id, jp.job_item_id, jp.part_id, jp.target_date, jp.batches,
                    j.delivery_date, pm.material_id, pm.qty
               FROM job_parts jp
               JOIN jobs j ON j.id = jp.job_id AND j.status = 'open'
               JOIN parts p ON p.id = jp.part_id AND p.deleted_at IS NULL
               JOIN part_materials pm ON pm.part_id = jp.part_id"
        ) as $r) {
            if (($progress[(int)$r['row_id']]['status'] ?? 'todo') === 'done') {
                continue;
            }
            $needs[] = [
                'job_id' => (int)$r['job_id'], 'item_id' => (int)$r['job_item_id'], 'row_id' => (int)$r['row_id'],
                'part_id' => (int)$r['part_id'], 'material_id' => (int)$r['material_id'],
                'use_date' => $r['target_date'], 'delivery_date' => $r['delivery_date'],
                'qty' => (float)$r['batches'] * (float)$r['qty'],
            ];
        }
        foreach (Db::all(
            "SELECT ji.id AS item_id, ji.job_id, ji.finish_date, ji.qty AS units, j.delivery_date, prm.material_id, prm.qty
               FROM job_items ji
               JOIN jobs j ON j.id = ji.job_id AND j.status = 'open'
               JOIN product_materials prm ON prm.product_id = ji.product_id"
        ) as $r) {
            $needs[] = [
                'job_id' => (int)$r['job_id'], 'item_id' => (int)$r['item_id'], 'row_id' => null,
                'part_id' => null, 'material_id' => (int)$r['material_id'],
                'use_date' => $r['finish_date'], 'delivery_date' => $r['delivery_date'],
                'qty' => (float)$r['units'] * (float)$r['qty'],
            ];
        }
        usort($needs, static fn($a, $b) => [$a['delivery_date'], $a['job_id'], $a['use_date'], (int)$a['row_id']]
                                        <=> [$b['delivery_date'], $b['job_id'], $b['use_date'], (int)$b['row_id']]);

        $materials = [];
        foreach (Db::all(
            'SELECT id, name, unit, is_stock_managed, purchase_qty, purchase_unit, supplier_id FROM materials'
        ) as $m) {
            $materials[(int)$m['id']] = $m;
        }
        $stock = [];
        foreach (Db::all('SELECT material_id, SUM(qty) AS qty FROM inventory GROUP BY material_id') as $r) {
            $stock[(int)$r['material_id']] = (float)$r['qty'];
        }
        $incoming = [];
        foreach (Db::all(
            "SELECT i.material_id, o.id AS order_id, o.order_no, o.status,
                    (i.qty - i.received_qty) * IFNULL(NULLIF(m.purchase_qty, 0), 1) AS qty,
                    IFNULL(o.desired_date, o.order_date) AS arrival
               FROM purchase_order_items i
               JOIN purchase_orders o ON o.id = i.order_id
               JOIN materials m ON m.id = i.material_id
              WHERE o.deleted_at IS NULL AND o.status IN ('ordered','partial') AND i.qty > i.received_qty
              ORDER BY arrival, o.id, i.id"
        ) as $r) {
            $r['qty'] = (float)$r['qty'];
            $incoming[(int)$r['material_id']][] = $r;
        }

        $leftStock = $stock;
        $leftPo    = [];
        foreach ($incoming as $mid => $list) {
            $leftPo[$mid] = array_map(static fn($r) => (float)$r['qty'], $list);
        }
        foreach ($needs as $k => $n) {
            $mid  = $n['material_id'];
            $need = $n['qty'];
            $res  = ['from_stock' => 0.0, 'from_po' => 0.0, 'short' => 0.0, 'arrival' => null, 'judge' => 'ok'];
            if ((int)($materials[$mid]['is_stock_managed'] ?? 1) === 0) {
                $needs[$k] = $n + array_merge($res, ['judge' => 'exempt']);
                continue;
            }
            $s = min($need, max(0.0, $leftStock[$mid] ?? 0.0));
            $leftStock[$mid] = ($leftStock[$mid] ?? 0.0) - $s;
            $need -= $s;
            $res['from_stock'] = $s;
            foreach ($leftPo[$mid] ?? [] as $i => $left) {
                if ($need <= self::EPS) {
                    break;
                }
                if ($left <= self::EPS) {
                    continue;
                }
                $t = min($need, $left);
                $leftPo[$mid][$i] -= $t;
                $need -= $t;
                $res['from_po'] += $t;
                $arr = $incoming[$mid][$i]['arrival'];
                $res['arrival'] = $res['arrival'] === null || $arr > $res['arrival'] ? $arr : $res['arrival'];
            }
            $res['short'] = $need > self::EPS ? $need : 0.0;
            if ($res['short'] > 0) {
                $res['judge'] = 'short';
            } elseif ($res['from_po'] > self::EPS) {
                $res['judge'] = $res['arrival'] !== null && $res['arrival'] > $n['use_date'] ? 'late' : 'ordered';
            }
            $needs[$k] = $n + $res;
        }

        return self::$materialsCache = [
            'needs'     => $needs,
            'materials' => $materials,
            'stock'     => $stock,
            'incoming'  => $incoming,
        ];
    }

    /**
     * 1材料の在庫の見込み（今日から $days 日）。今日より前の使う予定・納品予定は今日にまとめる。
     * @return array{stock: float, days: list<array{date:string, in:float, use:float, balance:float}>, later: array{in:float, use:float}, needs: list<array>, incoming: list<array>}
     *   later … 表示期間より後の納品予定・使う予定の合計
     */
    public static function projection(int $materialId, string $today, int $days): array
    {
        $all   = self::materials();
        $stock = $all['stock'][$materialId] ?? 0.0;
        $needs = array_values(array_filter($all['needs'], static fn($n) => $n['material_id'] === $materialId));
        $inc   = $all['incoming'][$materialId] ?? [];

        $byDay = [];
        foreach ($needs as $n) {
            $d = max($today, $n['use_date']);
            $byDay[$d]['use'] = ($byDay[$d]['use'] ?? 0.0) + $n['qty'];
        }
        foreach ($inc as $r) {
            $d = max($today, (string)$r['arrival']);
            $byDay[$d]['in'] = ($byDay[$d]['in'] ?? 0.0) + $r['qty'];
        }
        $rows    = [];
        $balance = $stock;
        $d       = $today;
        for ($i = 0; $i < $days; $i++) {
            $in  = $byDay[$d]['in'] ?? 0.0;
            $use = $byDay[$d]['use'] ?? 0.0;
            $balance += $in - $use;
            $rows[] = ['date' => $d, 'in' => $in, 'use' => $use, 'balance' => $balance];
            $d = Clock::shiftDays($d, 1);
        }
        $later = ['in' => 0.0, 'use' => 0.0];
        foreach ($byDay as $day => $v) {
            if ($day >= $d) {
                $later['in']  += $v['in'] ?? 0.0;
                $later['use'] += $v['use'] ?? 0.0;
            }
        }

        return ['stock' => $stock, 'days' => $rows, 'later' => $later, 'needs' => $needs, 'incoming' => $inc];
    }
}
