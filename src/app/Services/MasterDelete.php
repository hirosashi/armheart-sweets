<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Db;
use App\Core\View;

/**
 * 材料・部位・商品の削除。
 * 配合・構成・進行中の発注・在庫で使われているものは消さず、使っている所を返す。
 * 削除は deleted_at を入れる方式（在庫の履歴・過去の発注書は残す）。
 * コードは空にして、同じコードで登録し直せるようにする。
 */
class MasterDelete
{
    private const TABLES = ['material' => 'materials', 'part' => 'parts', 'product' => 'products'];

    /** 削除できない理由（使っている所）の一覧。空なら削除できる */
    public static function usages(string $type, int $id): array
    {
        return match ($type) {
            'material' => self::materialUsages($id),
            'part'     => self::partUsages($id),
            'product'  => self::productUsages($id),
        };
    }

    /** 削除する。使われていれば何もせず使っている所を返す */
    public static function delete(string $type, int $id): array
    {
        $usages = self::usages($type, $id);
        if ($usages !== []) {
            return $usages;
        }
        $table = self::TABLES[$type];
        $pdo   = Db::conn();
        $pdo->beginTransaction();
        try {
            self::remove($type, $table, $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return [];
    }

    private static function remove(string $type, string $table, int $id): void
    {
        Db::exec(
            "UPDATE {$table} SET deleted_at = ?, code = NULL, updated_by = ? WHERE id = ? AND deleted_at IS NULL",
            [Clock::now(), Auth::id(), $id]
        );
        if ($type === 'part') {
            Db::exec('DELETE FROM part_materials WHERE part_id = ?', [$id]);
        } elseif ($type === 'product') {
            Db::exec('DELETE FROM product_parts WHERE product_id = ?', [$id]);
            Db::exec('DELETE FROM product_materials WHERE product_id = ?', [$id]);
        }
    }

    private static function materialUsages(int $id): array
    {
        $out = [];
        foreach (Db::all(
            'SELECT p.name FROM part_materials pm JOIN parts p ON p.id = pm.part_id
              WHERE pm.material_id = ? AND p.deleted_at IS NULL ORDER BY p.name',
            [$id]
        ) as $r) {
            $out[] = '部位「' . $r['name'] . '」の配合';
        }
        foreach (Db::all(
            'SELECT p.name FROM product_materials pm JOIN products p ON p.id = pm.product_id
              WHERE pm.material_id = ? AND p.deleted_at IS NULL ORDER BY p.name',
            [$id]
        ) as $r) {
            $out[] = '商品「' . $r['name'] . '」の配合';
        }
        foreach (Db::all(
            "SELECT DISTINCT o.order_no, s.name AS supplier_name
               FROM purchase_order_items i
               JOIN purchase_orders o ON o.id = i.order_id
               JOIN suppliers s ON s.id = o.supplier_id
              WHERE i.material_id = ? AND o.deleted_at IS NULL
                AND o.status IN ('draft','ordered','partial')
              ORDER BY o.order_no",
            [$id]
        ) as $r) {
            $out[] = '納品が終わっていない材料の発注（' . $r['order_no'] . '・' . $r['supplier_name'] . '）';
        }
        $stock = (float)Db::value('SELECT IFNULL(SUM(qty),0) FROM inventory WHERE material_id = ?', [$id]);
        if ($stock > 0) {
            $unit  = (string)Db::value('SELECT unit FROM materials WHERE id = ?', [$id]);
            $out[] = '在庫が残っています（' . View::num($stock, 1) . $unit . '）。在庫の画面で0にしてください';
        }
        return $out;
    }

    private static function partUsages(int $id): array
    {
        $out = [];
        foreach (Db::all(
            'SELECT p.name FROM product_parts pp JOIN products p ON p.id = pp.product_id
              WHERE pp.part_id = ? AND p.deleted_at IS NULL ORDER BY p.name',
            [$id]
        ) as $r) {
            $out[] = '商品「' . $r['name'] . '」の構成';
        }
        foreach (Db::all(
            "SELECT DISTINCT j.customer_name, j.delivery_date, p.name AS product_name
               FROM job_parts jp
               JOIN jobs j ON j.id = jp.job_id
               JOIN job_items ji ON ji.id = jp.job_item_id
               JOIN products p ON p.id = ji.product_id
              WHERE jp.part_id = ? AND j.status = 'open'
              ORDER BY j.delivery_date",
            [$id]
        ) as $r) {
            $out[] = self::jobLabel($r);
        }
        return $out;
    }

    private static function productUsages(int $id): array
    {
        $out = [];
        foreach (Db::all(
            "SELECT DISTINCT j.customer_name, j.delivery_date, p.name AS product_name
               FROM job_items ji
               JOIN jobs j ON j.id = ji.job_id
               JOIN products p ON p.id = ji.product_id
              WHERE ji.product_id = ? AND j.status = 'open'
              ORDER BY j.delivery_date",
            [$id]
        ) as $r) {
            $out[] = self::jobLabel($r);
        }
        return $out;
    }

    private static function jobLabel(array $r): string
    {
        return '進行中の発注（' . trim(($r['customer_name'] ?? '') . ' ' . $r['product_name'])
            . '・納品 ' . Clock::d($r['delivery_date']) . '）';
    }
}
