<?php
namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\OperationLog;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;

class PartController
{
    /** 部位（パーツ）の一覧 */
    public static function index(): void
    {
        Auth::requireLogin();

        $parts = Db::all(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM part_materials pm WHERE pm.part_id = p.id) AS material_count,
                    (SELECT IFNULL(SUM(pm.qty),0) FROM part_materials pm WHERE pm.part_id = p.id) AS material_sum,
                    (SELECT COUNT(*) FROM product_parts pp WHERE pp.part_id = p.id) AS product_count
               FROM parts p
              WHERE p.deleted_at IS NULL
              ORDER BY p.is_shared DESC, p.name'
        );

        View::render('parts/index', ['parts' => $parts]);
    }

    /** 部位1件の配合（1バッチぶん） */
    public static function show(): void
    {
        Auth::requireLogin();

        $id   = (int)($_GET['id'] ?? 0);
        $part = Db::one('SELECT * FROM parts WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$part) {
            App::redirect('/parts');
        }

        $materials = Db::all(
            'SELECT pm.*, m.name AS material_name, m.unit, m.maker_name, m.is_stock_managed
               FROM part_materials pm
               JOIN materials m ON m.id = pm.material_id
              WHERE pm.part_id = ? AND m.deleted_at IS NULL
              ORDER BY pm.sort_no, m.name',
            [$id]
        );
        $products = Db::all(
            'SELECT pp.*, pr.name AS product_name
               FROM product_parts pp
               JOIN products pr ON pr.id = pp.product_id
              WHERE pp.part_id = ? AND pr.deleted_at IS NULL
              ORDER BY pr.name',
            [$id]
        );

        View::render('parts/show', [
            'part'      => $part,
            'materials' => $materials,
            'products'  => $products,
            'qty_sum'   => array_sum(array_map(static fn($r) => (float)$r['qty'], $materials)),
        ]);
    }

    /** 部位の登録・修正 */
    public static function save(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('parts')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect('/parts');
        }

        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim((string)($_POST['name'] ?? ''));
        $yield   = (float)($_POST['yield_rate'] ?? 0.9);
        $round   = isset($_POST['round_batch']) ? 1 : 0;
        $shared  = isset($_POST['is_shared']) ? 1 : 0;
        $note    = trim((string)($_POST['note'] ?? ''));

        $v = (new Validator())->required($name, '部位名')->maxLength($name, 100, '部位名');
        if ($v->fails() || $yield <= 0 || $yield > 1) {
            Session::flash('warn', '部位名を入力し、歩留まりは0より大きく1以下で入力してください。');
            App::redirect($id > 0 ? '/parts/show?id=' . $id : '/parts');
        }

        if ($id > 0) {
            Db::exec(
                'UPDATE parts SET name=?, yield_rate=?, round_batch=?, is_shared=?, note=?, updated_by=? WHERE id=?',
                [$name, $yield, $round, $shared, $note === '' ? null : $note, Auth::id(), $id]
            );
            OperationLog::write('update', 'parts', (string)$id, '部位を修正しました：' . $name);
            Session::flash('info', '部位を修正しました。');
        } else {
            $id = Db::insert(
                'INSERT INTO parts (name, unit, batch_total_qty, yield_rate, round_batch, is_shared, note, created_by)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$name, 'g', 0, $yield, $round, $shared, $note === '' ? null : $note, Auth::id()]
            );
            OperationLog::write('create', 'parts', (string)$id, '部位を登録しました：' . $name);
            Session::flash('info', '部位を登録しました。つづけて配合する材料を追加してください。');
        }

        App::redirect('/parts/show?id=' . $id);
    }

    /** 配合（1バッチの材料と配合量）の追加・修正・削除 */
    public static function saveMaterial(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('parts')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect('/parts');
        }

        $partId     = (int)($_POST['part_id'] ?? 0);
        $materialId = (int)($_POST['material_id'] ?? 0);

        if (($_POST['delete'] ?? '') === '1') {
            Db::exec('DELETE FROM part_materials WHERE part_id = ? AND material_id = ?', [$partId, $materialId]);
            OperationLog::write('delete', 'part_materials', $partId . '-' . $materialId, '配合から材料を外しました');
        } else {
            $qty = (float)($_POST['qty'] ?? 0);
            if ($materialId <= 0 || $qty <= 0) {
                Session::flash('warn', '材料と配合量（0より大きい数）を入力してください。');
                App::redirect('/parts/show?id=' . $partId);
            }
            Db::exec(
                'INSERT INTO part_materials (part_id, material_id, qty, sort_no) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE qty = VALUES(qty)',
                [$partId, $materialId, $qty, (int)($_POST['sort_no'] ?? 0)]
            );
            OperationLog::write('update', 'part_materials', $partId . '-' . $materialId, '配合を登録しました');
        }

        self::refreshBatchTotal($partId);
        Session::flash('info', '配合を更新しました。1バッチの合計量も計算しなおしました。');
        App::redirect('/parts/show?id=' . $partId);
    }

    /** 配合量の合計を「1バッチの合計量」に反映する */
    private static function refreshBatchTotal(int $partId): void
    {
        Db::exec(
            'UPDATE parts SET batch_total_qty =
                 (SELECT IFNULL(SUM(qty),0) FROM part_materials WHERE part_id = ?)
              WHERE id = ?',
            [$partId, $partId]
        );
    }
}
