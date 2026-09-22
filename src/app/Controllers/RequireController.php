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
use App\Services\Jobs;
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
        $jobId = (int)($_GET['job'] ?? 0) > 0 ? (int)$_GET['job'] : null;
        $job   = $jobId !== null ? Jobs::find($jobId) : null;
        if ($jobId !== null && $job === null) {
            Session::flash('warn', 'その発注は見つかりません。');
            App::redirect('/require');
        }
        if ($job !== null) {
            // 1件の発注に絞るときは、その発注の仕込み日〜仕上げ日をまとめて見る
            $first = Db::value('SELECT MIN(target_date) FROM job_parts WHERE job_id = ?', [$jobId]);
            $date  = $first ?: $job['finish_date'];
            $to    = max($job['finish_date'], (string)Db::value('SELECT IFNULL(MAX(target_date), ?) FROM job_parts WHERE job_id = ?', [$job['finish_date'], $jobId]));
            $days  = (int)Clock::parse($date)->diff(Clock::parse($to))->days + 1;
        }

        $materials = Requirement::materials($date, $to, $jobId);
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
        foreach (Requirement::plans($date, $to, $jobId) as $pl) {
            $plansByDay[$pl['target_date']][] = $pl;
        }
        $hasParts = $jobId !== null
            ? Db::value('SELECT COUNT(*) FROM job_parts WHERE job_id = ?', [$jobId]) > 0
            : Db::value('SELECT COUNT(*) FROM job_parts jp JOIN jobs j ON j.id = jp.job_id
                          WHERE j.status IN (\'open\',\'done\') AND jp.target_date BETWEEN ? AND ?', [$date, $to]) > 0;

        View::render('require/index', [
            'date'        => $date,
            'to'          => $to,
            'days'        => $days,
            'day_list'    => self::dayList($date, $days),
            'prev_date'   => Clock::shiftDays($date, -1),
            'next_date'   => Clock::shiftDays($date, 1),
            'only'        => $only,
            'job'         => $job,
            'job_id'      => $jobId,
            'plans_by_day' => $plansByDay,
            'has_data'    => $hasParts || $plansByDay !== [],
            'parts'       => Requirement::partsTotal($date, $to, $jobId),
            'materials'   => $materials,
            'need_by_day' => Requirement::materialsByDay($date, $to, $jobId),
            'summary'     => $summary,
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
        $jobId   = (int)($_POST['job'] ?? 0) > 0 ? (int)$_POST['job'] : null;
        $back    = $jobId !== null ? '/require?job=' . $jobId : '/require?date=' . $date . '&days=' . $days;
        $targets = array_map('intval', (array)($_POST['material_id'] ?? []));
        if ($targets === []) {
            Session::flash('warn', '発注に追加する材料を選んでください。');
            App::redirect($back);
        }

        $rows = array_filter(
            Requirement::materials($date, $to, $jobId),
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
                        order_date, period_from, period_to, job_id, desired_date, note, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [self::nextOrderNo(), $company['id'] ?? null, $supplierId, $company['delivery_place'] ?? null,
                 'draft', Clock::today(), $date, $to, $jobId, $date,
                 Clock::dayLabel($date) . '〜' . Clock::dayLabel($to) . 'の発注（つくる予定）から作成', Auth::id()]
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
            $msg .= '仕入先が未登録のため追加できなかった材料：' . implode('、', array_slice($noSupplier, 0, 5))
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
