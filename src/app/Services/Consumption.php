<?php
namespace App\Services;

use App\Core\Db;

/**
 * 製造による材料の自動引き当て。
 * 部位が「できあがり」になったとき、できた仕込み回数 × バッチ配合量 を
 * 在庫ロット（賞味期限の近い順）から引き、part_consumptions と inventory_adjustments に記録する。
 * 取り消し・回数の変更時は、いったん元に戻してから引き直す。
 */
class Consumption
{
    /** 対象週・部位の引き当てを元に戻す（記録も削除） */
    public static function revert(string $week, int $partId, ?int $userId): void
    {
        $rows = Db::all(
            'SELECT c.*, p.name AS part_name
               FROM part_consumptions c
               JOIN parts p ON p.id = c.part_id
              WHERE c.target_week = ? AND c.part_id = ?',
            [$week, $partId]
        );
        foreach ($rows as $r) {
            if ($r['inventory_id'] === null || (float)$r['applied_qty'] <= 0) {
                continue;
            }
            $lot = Db::one('SELECT qty FROM inventory WHERE id = ?', [(int)$r['inventory_id']]);
            if (!$lot) {
                continue;
            }
            $before = (float)$lot['qty'];
            $after  = $before + (float)$r['applied_qty'];
            Db::exec('UPDATE inventory SET qty = ?, updated_by = ? WHERE id = ?', [$after, $userId, (int)$r['inventory_id']]);
            Db::exec(
                'INSERT INTO inventory_adjustments (inventory_id, material_id, before_qty, after_qty, reason, note, created_by)
                 VALUES (?,?,?,?,?,?,?)',
                [(int)$r['inventory_id'], (int)$r['material_id'], $before, $after, 'other',
                 '製造の取り消しで戻しました：' . $r['part_name'], $userId]
            );
        }
        Db::exec('DELETE FROM part_consumptions WHERE target_week = ? AND part_id = ?', [$week, $partId]);
    }

    /**
     * できあがった仕込み回数ぶんを在庫から引く。
     * @return array{materials:int, short:array<int,array{name:string,qty:float}>}
     */
    public static function apply(string $week, int $partId, float $batches, ?int $userId): array
    {
        $part = Db::one('SELECT name FROM parts WHERE id = ?', [$partId]);
        $result = ['materials' => 0, 'short' => []];
        if (!$part || $batches <= 0) {
            return $result;
        }

        $lines = Db::all(
            'SELECT pm.material_id, pm.qty, m.name, m.is_stock_managed
               FROM part_materials pm
               JOIN materials m ON m.id = pm.material_id
              WHERE pm.part_id = ? AND m.deleted_at IS NULL
              ORDER BY pm.sort_no, pm.id',
            [$partId]
        );

        foreach ($lines as $line) {
            $materialId = (int)$line['material_id'];
            $need       = round($batches * (float)$line['qty'], 3);
            if ($need <= 0) {
                continue;
            }
            $result['materials']++;

            if ((int)$line['is_stock_managed'] !== 1) {
                self::record($week, $partId, $materialId, null, $batches, $need, 0.0, $userId);
                continue;
            }

            $remain = $need;
            $lots = Db::all(
                'SELECT id, qty FROM inventory
                  WHERE material_id = ? AND qty > 0
                  ORDER BY expiry_date IS NULL, expiry_date, id',
                [$materialId]
            );
            foreach ($lots as $lot) {
                if ($remain <= 0) {
                    break;
                }
                $before = (float)$lot['qty'];
                $take   = min($before, $remain);
                $after  = round($before - $take, 3);
                Db::exec('UPDATE inventory SET qty = ?, updated_by = ? WHERE id = ?', [$after, $userId, (int)$lot['id']]);
                Db::exec(
                    'INSERT INTO inventory_adjustments (inventory_id, material_id, before_qty, after_qty, reason, note, created_by)
                     VALUES (?,?,?,?,?,?,?)',
                    [(int)$lot['id'], $materialId, $before, $after, 'consume',
                     '製造で使用：' . $part['name'] . '（' . self::fmt($batches) . '回）', $userId]
                );
                self::record($week, $partId, $materialId, (int)$lot['id'], $batches, $take, $take, $userId);
                $remain = round($remain - $take, 3);
            }
            if ($remain > 0) {
                self::record($week, $partId, $materialId, null, $batches, $remain, 0.0, $userId);
                $result['short'][] = ['name' => $line['name'], 'qty' => $remain];
            }
        }
        return $result;
    }

    /** 材料ごとの、対象週に製造で使った量（在庫一覧用） */
    public static function usedByMaterial(string $week): array
    {
        $out = [];
        foreach (Db::all(
            'SELECT material_id, SUM(qty) AS qty, SUM(applied_qty) AS applied_qty
               FROM part_consumptions WHERE target_week = ? GROUP BY material_id',
            [$week]
        ) as $r) {
            $out[(int)$r['material_id']] = ['qty' => (float)$r['qty'], 'applied_qty' => (float)$r['applied_qty']];
        }
        return $out;
    }

    /** 1材料の、製造で使った内訳（週・部位ごと） */
    public static function detailByMaterial(int $materialId): array
    {
        return Db::all(
            'SELECT c.target_week, p.name AS part_name, MAX(c.batches) AS batches,
                    SUM(c.qty) AS qty, SUM(c.applied_qty) AS applied_qty, MAX(c.created_at) AS created_at
               FROM part_consumptions c
               JOIN parts p ON p.id = c.part_id
              WHERE c.material_id = ?
              GROUP BY c.target_week, c.part_id, p.name
              ORDER BY c.target_week DESC, p.name
              LIMIT 50',
            [$materialId]
        );
    }

    private static function record(
        string $week, int $partId, int $materialId, ?int $inventoryId,
        float $batches, float $qty, float $applied, ?int $userId
    ): void {
        Db::exec(
            'INSERT INTO part_consumptions
                (target_week, part_id, material_id, inventory_id, batches, qty, applied_qty, created_by)
             VALUES (?,?,?,?,?,?,?,?)',
            [$week, $partId, $materialId, $inventoryId, $batches, $qty, $applied, $userId]
        );
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }
}
