<?php
namespace App\Services;

use App\Core\Clock;
use App\Core\Db;

/**
 * 原材料発注一覧（Excelと同じ並びのかんたん画面）。
 * 商品＋製造数ごとに、部位→原材料と資材を並べ、配合・材料・発注・在庫の今のデータを読み書きする。
 *   B数 = 製造数 ÷ 1Bでできる数（切り上げ）、使用数 = 1B配合量 × B数、必要数 = 使用数 ÷ 発注単位
 */
class OrderSheet
{
    public const STORAGES = ['冷凍', '冷蔵', '常温'];

    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT s.*, p.name AS product_name
               FROM order_sheets s
               JOIN products p ON p.id = s.product_id
              WHERE s.id = ? AND s.deleted_at IS NULL',
            [$id]
        );
    }

    public static function all(): array
    {
        return Db::all(
            "SELECT s.*, p.name AS product_name,
                    (SELECT COUNT(*) FROM purchase_orders o
                      WHERE o.sheet_id = s.id AND o.deleted_at IS NULL AND o.status <> 'canceled') AS order_count,
                    (SELECT COUNT(*) FROM purchase_orders o
                      WHERE o.sheet_id = s.id AND o.deleted_at IS NULL AND o.status = 'delivered') AS done_count
               FROM order_sheets s
               JOIN products p ON p.id = s.product_id
              WHERE s.deleted_at IS NULL
              ORDER BY s.updated_at DESC, s.id DESC"
        );
    }

    public static function batches(int $qty, ?float $pieces): int
    {
        return ($pieces !== null && $pieces > 0) ? (int)ceil($qty / $pieces - 1e-9) : 0;
    }

    /**
     * 部位と原材料の行。発注は「部位×材料」ごとに分けて持つ（同じ材料を2つの部位で使っても別々に数える）。
     * @return list<array>
     */
    public static function parts(array $sheet): array
    {
        $parts = Db::all(
            'SELECT pp.id AS pp_id, pp.part_id, pp.batch_pieces, pp.fill_qty, pp.pieces_per_fill, pp.use_pieces,
                    p.name, p.unit, p.batch_total_qty, p.yield_rate
               FROM product_parts pp
               JOIN parts p ON p.id = pp.part_id AND p.deleted_at IS NULL
              WHERE pp.product_id = ?
              ORDER BY pp.sort_no, pp.id',
            [(int)$sheet['product_id']]
        );
        $orders = self::orders((int)$sheet['id']);
        $qty    = (int)$sheet['qty'];

        foreach ($parts as &$part) {
            $pieces = $part['batch_pieces'] !== null ? (float)$part['batch_pieces'] : null;
            $part['pieces_guess'] = false;
            if ($pieces === null && (float)$part['fill_qty'] > 0 && (int)$part['use_pieces'] > 0) {
                // 今の「商品の構成」から逆算した値を最初の目安として出す
                $pieces = round((float)$part['yield_rate'] * (float)$part['batch_total_qty'] * (int)$part['pieces_per_fill']
                                / ((float)$part['fill_qty'] * (int)$part['use_pieces']), 1);
                $part['pieces_guess'] = true;
            }
            $part['pieces']  = $pieces;
            $part['batches'] = self::batches($qty, $pieces);

            $rows = Db::all(
                'SELECT pm.material_id, pm.qty AS batch_qty, ' . self::MATERIAL_COLS . '
                   FROM part_materials pm
                   JOIN materials m ON m.id = pm.material_id AND m.deleted_at IS NULL
                  WHERE pm.part_id = ?
                  ORDER BY pm.sort_no, pm.id',
                [(int)$part['part_id']]
            );
            foreach ($rows as &$r) {
                $r['use_qty'] = (float)$r['batch_qty'] * $part['batches'];
                self::fill($r, $orders[(int)$part['part_id'] . '-' . (int)$r['material_id']] ?? []);
            }
            unset($r);
            $part['rows'] = $rows;
        }
        unset($part);
        return $parts;
    }

    /** 資材関連の行 */
    public static function packs(int $sheetId): array
    {
        $orders = self::orders($sheetId);
        $rows = Db::all(
            'SELECT sp.id AS sp_id, sp.purpose, sp.use_qty, sp.material_id, ' . self::MATERIAL_COLS . '
               FROM order_sheet_packs sp
               JOIN materials m ON m.id = sp.material_id AND m.deleted_at IS NULL
              WHERE sp.sheet_id = ?
              ORDER BY sp.sort_no, sp.id',
            [$sheetId]
        );
        foreach ($rows as &$r) {
            $r['use_qty'] = $r['use_qty'] !== null ? (float)$r['use_qty'] : 0.0;
            self::fill($r, $orders['0-' . (int)$r['material_id']] ?? []);
        }
        unset($r);
        return $rows;
    }

    private const MATERIAL_COLS =
        'm.name, m.unit, m.kind, m.purchase_unit, m.purchase_qty, m.vendor_name, m.storage,
         (SELECT IFNULL(SUM(i.qty),0) FROM inventory i WHERE i.material_id = m.id) AS stock_qty';

    /** 必要数・発注の合計・過不足を足す */
    private static function fill(array &$r, array $orders): void
    {
        $per = (float)($r['purchase_qty'] ?? 0);
        $r['need']    = $per > 0 ? $r['use_qty'] / $per : null;
        $r['orders']  = $orders;
        $r['ordered'] = array_sum(array_map(fn($o) => (float)$o['qty'], $orders));
        $r['diff']    = $r['need'] !== null ? $r['ordered'] - $r['need'] : null;
        $r['stock_packs'] = $per > 0 ? (float)$r['stock_qty'] / $per : null;
    }

    /**
     * この一覧から作った材料の発注（取消は除く）。キーは「部位ID-材料ID」（資材は 0-材料ID）
     * @return array<string, list<array>>
     */
    public static function orders(int $sheetId): array
    {
        $rows = Db::all(
            "SELECT o.id AS order_id, o.order_no, o.status, o.order_date, o.desired_date, o.vendor_name,
                    o.sheet_part_id, i.id AS item_id, i.material_id, i.qty, i.received_qty, i.unit
               FROM purchase_orders o
               JOIN purchase_order_items i ON i.order_id = o.id
              WHERE o.sheet_id = ? AND o.deleted_at IS NULL AND o.status <> 'canceled'
              ORDER BY o.desired_date IS NULL, o.desired_date, o.order_date, o.id",
            [$sheetId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)($r['sheet_part_id'] ?? 0) . '-' . (int)$r['material_id']][] = $r;
        }
        return $out;
    }

    /** Excel（.xlsx）に書き出す行。並びは画面と同じ */
    public static function excelRows(array $sheet, array $parts, array $packs): array
    {
        $n = static fn($v, int $d = 3) => $v === null ? '' : round((float)$v, $d);
        $rows = [[
            '部位名', '発注先', '原材料名', '１B配合量', '使用数', '発注単位', '必要数（単位）',
            '発注数', '単位', '発注日', '納品日', '状態（済など）', '保管配送', '在庫（' . Clock::d(Clock::today()) . '）',
        ]];
        foreach ($parts as $part) {
            $first = true;
            foreach ($part['rows'] as $r) {
                $orders = $r['orders'] ?: [null];
                foreach ($orders as $k => $o) {
                    $rows[] = [
                        $first ? $part['name'] : '',
                        $k === 0 ? (string)$r['vendor_name'] : '',
                        $k === 0 ? $r['name'] : '',
                        $k === 0 ? $n($r['batch_qty']) : '',
                        $k === 0 ? $n($r['use_qty']) : '',
                        $k === 0 ? $n($r['purchase_qty']) : '',
                        $k === 0 ? $n($r['need'], 2) : '',
                        $o ? $n($o['qty']) : '',
                        $k === 0 ? (string)$r['purchase_unit'] : '',
                        $o ? Clock::d($o['order_date']) : '',
                        $o ? Clock::d($o['desired_date']) : '',
                        $o && $o['status'] === 'delivered' ? '済' : '',
                        $k === 0 ? (string)$r['storage'] : '',
                        $k === 0 ? $n($r['stock_qty']) : '',
                    ];
                    $first = false;
                }
            }
        }
        $rows[] = [];
        $rows[] = ['資材関連'];
        $rows[] = ['発注先', '資材名', '使用用途', '使用数', '発注数', '発注単位', '発注日', '納品日', '状態（済など）'];
        foreach ($packs as $r) {
            $orders = $r['orders'] ?: [null];
            foreach ($orders as $k => $o) {
                $rows[] = [
                    $k === 0 ? (string)$r['vendor_name'] : '',
                    $k === 0 ? $r['name'] : '',
                    $k === 0 ? (string)$r['purpose'] : '',
                    $k === 0 ? $n($r['use_qty']) : '',
                    $o ? $n($o['qty']) : '',
                    $k === 0 ? (string)$r['purchase_unit'] : '',
                    $o ? Clock::d($o['order_date']) : '',
                    $o ? Clock::d($o['desired_date']) : '',
                    $o && $o['status'] === 'delivered' ? '済' : '',
                ];
            }
        }
        $rows[] = [];
        $rows[] = ['製造数', (int)$sheet['qty']];
        $rows[] = ['部位名', '1Bでできる数', 'B数'];
        foreach ($parts as $part) {
            $rows[] = [$part['name'], $n($part['pieces'], 1), $part['batches']];
        }
        return $rows;
    }
}
