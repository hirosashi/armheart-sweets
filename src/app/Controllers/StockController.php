<?php
namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\OperationLog;
use App\Core\Session;
use App\Core\View;
use App\Services\Consumption;

class StockController
{
    public const REASONS = [
        'stocktake' => '棚卸し',
        'receive'   => '入荷',
        'consume'   => '使用',
        'loss'      => '廃棄',
        'other'     => 'その他',
    ];

    /** 材料の在庫（今ある材料） */
    public static function index(): void
    {
        Auth::requireLogin();

        $keyword = trim((string)($_GET['q'] ?? ''));
        $filter  = (string)($_GET['filter'] ?? '');

        $where  = ['m.deleted_at IS NULL', 'm.is_stock_managed = 1'];
        $params = [];
        if ($keyword !== '') {
            $where[]  = '(m.name LIKE ? OR m.alias_names LIKE ? OR m.maker_name LIKE ?)';
            $like     = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($filter === 'instock') {
            $where[] = '(SELECT IFNULL(SUM(qty),0) FROM inventory i WHERE i.material_id = m.id) > 0';
        } elseif ($filter === 'expiring') {
            $where[]  = '(SELECT MIN(i.expiry_date) FROM inventory i WHERE i.material_id = m.id AND i.qty > 0) <= ?';
            $params[] = Clock::daysLater(5);
        }
        $whereSql = implode(' AND ', $where);

        $materials = Db::all(
            "SELECT m.id, m.name, m.unit, m.maker_name, m.purchase_unit, m.purchase_qty,
                    (SELECT IFNULL(SUM(i.qty),0) FROM inventory i WHERE i.material_id = m.id) AS stock_qty,
                    (SELECT MIN(i.expiry_date) FROM inventory i WHERE i.material_id = m.id AND i.qty > 0) AS nearest_expiry,
                    m.expiry_alert_days
               FROM materials m
              WHERE {$whereSql}
              ORDER BY (SELECT IFNULL(SUM(i.qty),0) FROM inventory i WHERE i.material_id = m.id) > 0 DESC, m.name
              LIMIT 300",
            $params
        );

        $week = Clock::weekStart(Clock::normalizeDate($_GET['week'] ?? null) ?? Clock::weekStart());

        View::render('stock/index', [
            'materials' => $materials,
            'week'      => $week,
            'used'      => Consumption::usedByMaterial($week),
            'keyword'   => $keyword,
            'filter'    => $filter,
            'today'     => Clock::today(),
            'total'     => (int)Db::value('SELECT COUNT(*) FROM materials WHERE deleted_at IS NULL AND is_stock_managed = 1'),
        ]);
    }

    /** 1材料の在庫の内訳と調整履歴 */
    public static function show(): void
    {
        Auth::requireLogin();

        $id       = (int)($_GET['id'] ?? 0);
        $material = Db::one('SELECT * FROM materials WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$material) {
            App::redirect('/stock');
        }

        View::render('stock/show', [
            'material' => $material,
            'lots'     => Db::all('SELECT * FROM inventory WHERE material_id = ? ORDER BY expiry_date IS NULL, expiry_date, id', [$id]),
            'history'  => Db::all(
                'SELECT a.*, u.name AS user_name
                   FROM inventory_adjustments a
              LEFT JOIN users u ON u.id = a.created_by
                  WHERE a.material_id = ?
                  ORDER BY a.created_at DESC, a.id DESC
                  LIMIT 50',
                [$id]
            ),
            'today'    => Clock::today(),
            'consumed' => Consumption::detailByMaterial($id),
        ]);
    }

    /** 棚卸し調整（数量を実際の数に合わせる） */
    public static function adjust(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('stock')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect('/stock');
        }

        $materialId  = (int)($_POST['material_id'] ?? 0);
        $inventoryId = (int)($_POST['inventory_id'] ?? 0);
        $afterQty    = (float)($_POST['after_qty'] ?? 0);
        $reason      = (string)($_POST['reason'] ?? 'stocktake');
        $note        = trim((string)($_POST['note'] ?? ''));
        $expiry      = Clock::normalizeDate($_POST['expiry_date'] ?? null);
        $location    = trim((string)($_POST['location'] ?? ''));

        if ($materialId <= 0 || $afterQty < 0 || !isset(self::REASONS[$reason])) {
            Session::flash('warn', '数量は0以上で入力してください。');
            App::redirect('/stock/show?id=' . $materialId);
        }

        $before = null;
        if ($inventoryId > 0) {
            $lot = Db::one('SELECT * FROM inventory WHERE id = ? AND material_id = ?', [$inventoryId, $materialId]);
            if ($lot) {
                $before = (float)$lot['qty'];
                Db::exec(
                    'UPDATE inventory SET qty = ?, expiry_date = ?, location = ?, updated_by = ? WHERE id = ?',
                    [$afterQty, $expiry, $location === '' ? null : $location, Auth::id(), $inventoryId]
                );
            }
        } else {
            $inventoryId = Db::insert(
                'INSERT INTO inventory (material_id, lot_no, qty, expiry_date, location, updated_by)
                 VALUES (?,?,?,?,?,?)',
                [$materialId, trim((string)($_POST['lot_no'] ?? '')) ?: null, $afterQty, $expiry,
                 $location === '' ? null : $location, Auth::id()]
            );
        }

        Db::exec(
            'INSERT INTO inventory_adjustments (inventory_id, material_id, before_qty, after_qty, reason, note, created_by)
             VALUES (?,?,?,?,?,?,?)',
            [$inventoryId, $materialId, $before, $afterQty, $reason, $note === '' ? null : $note, Auth::id()]
        );
        OperationLog::write('update', 'inventory', (string)$materialId, '在庫を調整しました（' . self::REASONS[$reason] . '）');
        Session::flash('info', '在庫を更新しました。');
        App::redirect('/stock/show?id=' . $materialId);
    }
}
