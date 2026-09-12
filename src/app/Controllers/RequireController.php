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
use App\Services\Requirement;

class RequireController
{
    /** 必要な材料と足りない分 */
    public static function index(): void
    {
        Auth::requireLogin();

        $week = Clock::normalizeDate($_GET['week'] ?? null) ?? Clock::weekStart();
        $week = Clock::weekStart($week);
        $only = ($_GET['only'] ?? '') === 'short';

        $materials = Requirement::materials($week);
        $summary = ['short' => 0, 'tight' => 0, 'ok' => 0, 'exempt' => 0];
        foreach ($materials as $row) {
            $summary[$row['judge']]++;
        }
        if ($only) {
            $materials = array_values(array_filter(
                $materials,
                static fn($r) => in_array($r['judge'], ['short', 'tight'], true)
            ));
        }

        View::render('require/index', [
            'week'       => $week,
            'prev_week'  => Clock::shiftWeek($week, -1),
            'next_week'  => Clock::shiftWeek($week, 1),
            'only'       => $only,
            'plans'      => Requirement::plans($week),
            'parts'      => Requirement::parts($week),
            'materials'  => $materials,
            'summary'    => $summary,
            'products'   => Db::all('SELECT id, name FROM products WHERE deleted_at IS NULL ORDER BY name'),
        ]);
    }

    /** 「今週つくる数」の登録 */
    public static function savePlan(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('require')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect('/require');
        }

        $week = Clock::weekStart(Clock::normalizeDate($_POST['week'] ?? null) ?? Clock::weekStart());

        // 既存の行（一覧の入力欄）をまとめて更新する
        foreach ((array)($_POST['plan_qty'] ?? []) as $productId => $qty) {
            $productId = (int)$productId;
            $qty       = (int)$qty;
            if ($qty > 0) {
                Db::exec(
                    'INSERT INTO production_plans (target_week, product_id, qty, created_by)
                     VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_by = VALUES(created_by)',
                    [$week, $productId, $qty, Auth::id()]
                );
            } else {
                Db::exec('DELETE FROM production_plans WHERE target_week = ? AND product_id = ?', [$week, $productId]);
            }
        }

        // 追加行（商品を選んで数を入れる）
        $newProduct = (int)($_POST['new_product_id'] ?? 0);
        $newQty     = (int)($_POST['new_qty'] ?? 0);
        if ($newProduct > 0 && $newQty > 0) {
            Db::exec(
                'INSERT INTO production_plans (target_week, product_id, qty, created_by) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_by = VALUES(created_by)',
                [$week, $newProduct, $newQty, Auth::id()]
            );
        }

        OperationLog::write('update', 'production_plans', $week, 'つくる数を登録しました');
        Session::flash('info', 'つくる数を登録しました。必要な材料を計算しなおしました。');
        App::redirect('/require?week=' . $week);
    }

    /** 足りない材料を発注（未発注）に追加する */
    public static function createOrders(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('order')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect('/require');
        }

        $week     = Clock::weekStart(Clock::normalizeDate($_POST['week'] ?? null) ?? Clock::weekStart());
        $targets  = array_map('intval', (array)($_POST['material_id'] ?? []));
        if ($targets === []) {
            Session::flash('warn', '発注に追加する材料を選んでください。');
            App::redirect('/require?week=' . $week);
        }

        $rows = array_filter(
            Requirement::materials($week),
            static fn($r) => in_array((int)$r['id'], $targets, true)
                && $r['order_qty'] !== null && (float)$r['order_qty'] > 0
        );

        $company   = Db::one('SELECT * FROM companies WHERE deleted_at IS NULL ORDER BY is_default DESC, sort_no LIMIT 1');
        $bySupplier = [];
        $noSupplier = [];
        foreach ($rows as $row) {
            if (empty($row['supplier_id'])) {
                $noSupplier[] = $row['name'];
                continue;
            }
            $bySupplier[(int)$row['supplier_id']][] = $row;
        }

        $created = 0;
        foreach ($bySupplier as $supplierId => $items) {
            $orderId = Db::insert(
                'INSERT INTO purchase_orders (order_no, company_id, supplier_id, delivery_place, status,
                        order_date, desired_date, note, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [self::nextOrderNo(), $company['id'] ?? null, $supplierId, $company['delivery_place'] ?? null,
                 'draft', Clock::today(), Clock::daysLater(7),
                 Clock::d($week) . 'の週の生産計画から作成', Auth::id()]
            );
            foreach ($items as $i => $row) {
                Db::exec(
                    'INSERT INTO purchase_order_items (order_id, material_id, item_name, qty, unit, sort_no)
                     VALUES (?,?,?,?,?,?)',
                    [$orderId, (int)$row['id'], $row['name'], (float)$row['order_qty'],
                     $row['purchase_unit'], $i + 1]
                );
            }
            OperationLog::write('create', 'purchase_orders', (string)$orderId, '不足分から発注を作成しました');
            $created++;
        }

        $msg = $created > 0
            ? "発注（未発注）を{$created}件つくりました。「発注の管理」で内容を確認して発注書を印刷できます。"
            : '発注に追加できる材料がありませんでした。';
        if ($noSupplier !== []) {
            $msg .= '業者が未登録のため追加できなかった材料：' . implode('、', array_slice($noSupplier, 0, 5))
                 . (count($noSupplier) > 5 ? ' ほか' . (count($noSupplier) - 5) . '件' : '');
        }
        Session::flash($created > 0 ? 'info' : 'warn', $msg);
        App::redirect('/orders');
    }

    /** 発注番号（PO-YYYYMMDD-連番） */
    private static function nextOrderNo(): string
    {
        $prefix = 'PO-' . str_replace('-', '', Clock::today()) . '-';
        $count  = (int)Db::value('SELECT COUNT(*) FROM purchase_orders WHERE order_no LIKE ?', [$prefix . '%']);
        return $prefix . sprintf('%02d', $count + 1);
    }
}
