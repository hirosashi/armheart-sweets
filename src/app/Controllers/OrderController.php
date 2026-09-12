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

class OrderController
{
    public const STATUS_LABELS = [
        'draft'     => '未発注',
        'ordered'   => '発注済',
        'delivered' => '納品済',
        'canceled'  => '取消',
    ];

    /** 発注の一覧 */
    public static function index(): void
    {
        Auth::requireLogin();

        $status = (string)($_GET['status'] ?? '');
        $params = [];
        $where  = 'o.deleted_at IS NULL';
        if (isset(self::STATUS_LABELS[$status])) {
            $where .= ' AND o.status = ?';
            $params[] = $status;
        }

        $orders = Db::all(
            "SELECT o.*, s.name AS supplier_name, c.name AS company_name,
                    (SELECT COUNT(*) FROM purchase_order_items i WHERE i.order_id = o.id) AS item_count
               FROM purchase_orders o
               JOIN suppliers s ON s.id = o.supplier_id
          LEFT JOIN companies c ON c.id = o.company_id
              WHERE {$where}
              ORDER BY o.order_date DESC, o.id DESC",
            $params
        );

        View::render('orders/index', ['orders' => $orders, 'status' => $status]);
    }

    /** 発注1件の内容 */
    public static function show(): void
    {
        Auth::requireLogin();

        $id    = (int)($_GET['id'] ?? 0);
        $order = self::find($id);
        if (!$order) {
            App::redirect('/orders');
        }

        View::render('orders/show', [
            'order'     => $order,
            'items'     => self::items($id),
            'companies' => Db::all('SELECT * FROM companies WHERE deleted_at IS NULL ORDER BY sort_no, id'),
        ]);
    }

    /** 発注書（印刷用・ブラウザの印刷からPDFにできる） */
    public static function print(): void
    {
        Auth::requireLogin();

        $id    = (int)($_GET['id'] ?? 0);
        $order = self::find($id);
        if (!$order) {
            App::redirect('/orders');
        }

        View::render('orders/print', [
            'order' => $order,
            'items' => self::items($id),
        ], false);
    }

    /** 発注内容の更新（発注元・希望納期・納品場所・備考・数量） */
    public static function save(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('order')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect('/orders');
        }

        $id = (int)($_POST['id'] ?? 0);
        if (!self::find($id)) {
            App::redirect('/orders');
        }

        $companyId = (int)($_POST['company_id'] ?? 0);
        Db::exec(
            'UPDATE purchase_orders SET company_id = ?, delivery_place = ?, order_date = ?, desired_date = ?,
                    note = ?, updated_by = ?
              WHERE id = ?',
            [$companyId > 0 ? $companyId : null,
             trim((string)($_POST['delivery_place'] ?? '')) ?: null,
             Clock::normalizeDate($_POST['order_date'] ?? null),
             Clock::normalizeDate($_POST['desired_date'] ?? null),
             trim((string)($_POST['note'] ?? '')) ?: null,
             Auth::id(), $id]
        );

        foreach ((array)($_POST['item_qty'] ?? []) as $itemId => $qty) {
            $itemId = (int)$itemId;
            $qty    = (float)$qty;
            if ($qty > 0) {
                Db::exec(
                    'UPDATE purchase_order_items SET qty = ?, item_name = ?, note = ? WHERE id = ? AND order_id = ?',
                    [$qty,
                     trim((string)($_POST['item_name'][$itemId] ?? '')) ?: null,
                     trim((string)($_POST['item_note'][$itemId] ?? '')) ?: null,
                     $itemId, $id]
                );
            } else {
                Db::exec('DELETE FROM purchase_order_items WHERE id = ? AND order_id = ?', [$itemId, $id]);
            }
        }

        OperationLog::write('update', 'purchase_orders', (string)$id, '発注内容を修正しました');
        Session::flash('info', '発注内容を保存しました。');
        App::redirect('/orders/show?id=' . $id);
    }

    /** 発注の状態を進める（未発注→発注済→納品済 / 取消） */
    public static function updateStatus(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('order')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect('/orders');
        }

        $id     = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if (!self::find($id) || !isset(self::STATUS_LABELS[$status])) {
            App::redirect('/orders');
        }

        Db::exec(
            'UPDATE purchase_orders SET status = ?,
                    order_date = CASE WHEN ? = \'ordered\' AND order_date IS NULL THEN ? ELSE order_date END,
                    delivered_date = CASE WHEN ? = \'delivered\' THEN ? ELSE delivered_date END,
                    updated_by = ?
              WHERE id = ?',
            [$status, $status, Clock::today(), $status, Clock::today(), Auth::id(), $id]
        );
        OperationLog::write('update', 'purchase_orders', (string)$id, '発注の状態を' . self::STATUS_LABELS[$status] . 'にしました');
        Session::flash('info', '状態を「' . self::STATUS_LABELS[$status] . '」にしました。');
        App::redirect('/orders/show?id=' . $id);
    }

    private static function find(int $id): ?array
    {
        return Db::one(
            'SELECT o.*, s.name AS supplier_name, c.name AS company_name, c.zip AS company_zip,
                    c.address AS company_address, c.tel AS company_tel, c.fax AS company_fax
               FROM purchase_orders o
               JOIN suppliers s ON s.id = o.supplier_id
          LEFT JOIN companies c ON c.id = o.company_id
              WHERE o.id = ? AND o.deleted_at IS NULL',
            [$id]
        );
    }

    private static function items(int $orderId): array
    {
        return Db::all(
            'SELECT i.*, m.name AS material_name, m.unit AS material_unit
               FROM purchase_order_items i
               JOIN materials m ON m.id = i.material_id
              WHERE i.order_id = ?
              ORDER BY i.sort_no, i.id',
            [$orderId]
        );
    }
}
