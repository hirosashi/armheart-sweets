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
use App\Services\Progress;
use App\Services\Requirement;

/** スケジュール（月〜日の週カレンダー）。日ごとのつくる数と部位の仕込み状況を一覧し、その日の画面へ入る */
class ScheduleController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $week = Clock::weekStart(Clock::normalizeDate($_GET['week'] ?? null) ?? Clock::today());
        $days = Clock::weekDays($week);
        $to   = $days[6];

        $plansByDay = array_fill_keys($days, []);
        foreach (Requirement::plans($week, $to) as $pl) {
            $plansByDay[$pl['target_date']][] = $pl;
        }

        $cardsByDay = Progress::cardsByDay($week, $to);
        $statusCount = [];
        foreach ($cardsByDay as $d => $cards) {
            $c = ['todo' => 0, 'doing' => 0, 'done' => 0, 'carried' => 0];
            foreach ($cards as $card) {
                $c[$card['status']]++;
                if ($card['carried'] > 0) {
                    $c['carried']++;
                }
            }
            $statusCount[$d] = $c;
        }

        View::render('schedule/index', [
            'week'          => $week,
            'days'          => $days,
            'to'            => $to,
            'prev_week'     => Clock::shiftWeek($week, -1),
            'next_week'     => Clock::shiftWeek($week, 1),
            'this_week'     => Clock::weekStart(Clock::today()),
            'today'         => Clock::today(),
            'plans_by_day'  => $plansByDay,
            'cards_by_day'  => $cardsByDay,
            'status_count'  => $statusCount,
            'products'      => Db::all('SELECT id, name FROM products WHERE deleted_at IS NULL ORDER BY name, id'),
            'edit_date'     => Clock::normalizeDate($_GET['edit'] ?? null),
        ]);
    }

    /** 日マスからのつくる数の保存（商品と台数） */
    public static function savePlan(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $date = Clock::normalizeDate($_POST['date'] ?? null) ?? Clock::today();
        $week = Clock::weekStart($date);
        if (!Auth::can('require')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect('/schedule?week=' . $week);
        }

        $pdo = Db::conn();
        $pdo->beginTransaction();
        try {
            foreach ((array)($_POST['plan_qty'] ?? []) as $productId => $qty) {
                self::upsert($date, (int)$productId, (int)$qty);
            }
            $newId = (int)($_POST['new_product_id'] ?? 0);
            if ($newId > 0) {
                self::upsert($date, $newId, (int)($_POST['new_qty'] ?? 0));
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        OperationLog::write('update', 'production_plans', $date, 'スケジュールでつくる数を入力しました');
        Session::flash('info', Clock::dayLabel($date) . ' のつくる数を保存しました。');
        App::redirect('/schedule?week=' . $week);
    }

    private static function upsert(string $date, int $productId, int $qty): void
    {
        if ($productId <= 0) {
            return;
        }
        if ($qty <= 0) {
            Db::exec('DELETE FROM production_plans WHERE target_date = ? AND product_id = ?', [$date, $productId]);
            return;
        }
        Db::exec(
            'INSERT INTO production_plans (target_date, product_id, qty, created_by) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_by = VALUES(created_by)',
            [$date, $productId, $qty, Auth::id()]
        );
    }
}
