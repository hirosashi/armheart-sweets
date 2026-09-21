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
    /** 必要な材料と足りない分（開始日から先読み期間ぶんをまとめて見る） */
    public static function index(): void
    {
        Auth::requireLogin();

        $date = Clock::normalizeDate($_GET['date'] ?? null) ?? Clock::today();
        $days = Requirement::normalizeDays($_GET['days'] ?? Requirement::DEFAULT_DAYS);
        $to   = Clock::rangeEnd($date, $days);
        $only = ($_GET['only'] ?? '') === 'short';

        $materials = Requirement::materials($date, $to);
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

        $plansByDay = [];
        foreach (Requirement::plans($date, $to) as $pl) {
            $plansByDay[$pl['target_date']][] = $pl;
        }

        View::render('require/index', [
            'date'        => $date,
            'to'          => $to,
            'days'        => $days,
            'day_list'    => self::dayList($date, $days),
            'prev_date'   => Clock::shiftDays($date, -1),
            'next_date'   => Clock::shiftDays($date, 1),
            'only'        => $only,
            'plans'       => $plansByDay[$date] ?? [],
            'plans_by_day' => $plansByDay,
            'parts'       => Requirement::partsTotal($date, $to),
            'materials'   => $materials,
            'need_by_day' => Requirement::materialsByDay($date, $to),
            'summary'     => $summary,
            'products'    => Db::all('SELECT id, name FROM products WHERE deleted_at IS NULL ORDER BY name'),
        ]);
    }

    private static function dayList(string $from, int $days): array
    {
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $out[] = Clock::shiftDays($from, $i);
        }
        return $out;
    }

    /** その日につくる数の登録（必要な材料の画面・スケジュールの両方から使う） */
    public static function savePlan(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('require')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect('/require');
        }

        $date = Clock::normalizeDate($_POST['date'] ?? null) ?? Clock::today();
        $back = ($_POST['back'] ?? '') === 'schedule'
            ? '/schedule?week=' . Clock::weekStart($date)
            : '/require?date=' . $date . '&days=' . Requirement::normalizeDays($_POST['days'] ?? Requirement::DEFAULT_DAYS);

        // 既存の行（一覧の入力欄）をまとめて更新する
        foreach ((array)($_POST['plan_qty'] ?? []) as $productId => $qty) {
            $productId = (int)$productId;
            $qty       = (int)$qty;
            if ($qty > 0) {
                Db::exec(
                    'INSERT INTO production_plans (target_date, product_id, qty, created_by)
                     VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_by = VALUES(created_by)',
                    [$date, $productId, $qty, Auth::id()]
                );
            } else {
                Db::exec('DELETE FROM production_plans WHERE target_date = ? AND product_id = ?', [$date, $productId]);
            }
        }

        // 追加行（商品を選んで数を入れる）
        $newProduct = (int)($_POST['new_product_id'] ?? 0);
        $newQty     = (int)($_POST['new_qty'] ?? 0);
        if ($newProduct > 0 && $newQty > 0) {
            Db::exec(
                'INSERT INTO production_plans (target_date, product_id, qty, created_by) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_by = VALUES(created_by)',
                [$date, $newProduct, $newQty, Auth::id()]
            );
        }

        OperationLog::write('update', 'production_plans', $date, Clock::dayLabel($date) . 'のつくる数を登録しました');
        Session::flash('info', Clock::dayLabel($date) . 'のつくる数を登録しました。必要な材料を計算しなおしました。');
        App::redirect($back);
    }

    /** 足りない材料を発注（未発注）に追加する。発注には対象期間（どの日ぶんか）を記録する */
    public static function createOrders(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('order')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect('/require');
        }

        $date    = Clock::normalizeDate($_POST['date'] ?? null) ?? Clock::today();
        $days    = Requirement::normalizeDays($_POST['days'] ?? Requirement::DEFAULT_DAYS);
        $to      = Clock::rangeEnd($date, $days);
        $back    = '/require?date=' . $date . '&days=' . $days;
        $targets = array_map('intval', (array)($_POST['material_id'] ?? []));
        if ($targets === []) {
            Session::flash('warn', '発注に追加する材料を選んでください。');
            App::redirect($back);
        }

        $rows = array_filter(
            Requirement::materials($date, $to),
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
                        order_date, period_from, period_to, desired_date, note, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [self::nextOrderNo(), $company['id'] ?? null, $supplierId, $company['delivery_place'] ?? null,
                 'draft', Clock::today(), $date, $to, $date,
                 Clock::dayLabel($date) . '〜' . Clock::dayLabel($to) . 'のつくる数から作成', Auth::id()]
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
