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
use App\Services\Requirement;

class ProgressController
{
    public const STATUS_LABELS = [
        'todo'  => 'これから',
        'doing' => '仕込み中',
        'done'  => 'できあがり',
    ];

    /** 部位の進み具合（今週つくる部位を、これから／仕込み中／できあがりで管理） */
    public static function index(): void
    {
        Auth::requireLogin();

        $week = Clock::weekStart(Clock::normalizeDate($_GET['week'] ?? null) ?? Clock::weekStart());

        $needs = Requirement::parts($week);
        $saved = [];
        foreach (Db::all('SELECT * FROM part_progress WHERE target_week = ?', [$week]) as $row) {
            $saved[(int)$row['part_id']] = $row;
        }

        $columns = ['todo' => [], 'doing' => [], 'done' => []];
        foreach ($needs as $need) {
            $row = $saved[(int)$need['id']] ?? null;
            $card = [
                'part_id'     => (int)$need['id'],
                'part_name'   => $need['name'],
                'unit'        => $need['unit'],
                'batches'     => (float)$need['batches'],
                'need_qty'    => (float)$need['need_qty'],
                'done_qty'    => $row ? (float)$row['done_qty'] : 0.0,
                'status'      => $row['status'] ?? 'todo',
                'assignee'    => $row['assignee'] ?? null,
                'note'        => $row['note'] ?? null,
                'updated_at'  => $row['updated_at'] ?? null,
            ];
            $columns[$card['status']][] = $card;
        }

        View::render('progress/index', [
            'week'      => $week,
            'prev_week' => Clock::shiftWeek($week, -1),
            'next_week' => Clock::shiftWeek($week, 1),
            'columns'   => $columns,
            'has_plan'  => Requirement::plans($week) !== [],
        ]);
    }

    /** 進み具合の更新 */
    public static function save(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('progress')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect('/progress');
        }

        $week   = Clock::weekStart(Clock::normalizeDate($_POST['week'] ?? null) ?? Clock::weekStart());
        $partId = (int)($_POST['part_id'] ?? 0);
        $status = (string)($_POST['status'] ?? 'todo');
        if ($partId <= 0 || !isset(self::STATUS_LABELS[$status])) {
            App::redirect('/progress?week=' . $week);
        }

        $planned = (float)($_POST['planned_qty'] ?? 0);
        $done    = (float)($_POST['done_qty'] ?? 0);
        if ($done < 0) {
            Session::flash('warn', 'できた回数は0以上で入力してください。');
            App::redirect('/progress?week=' . $week);
        }
        // できあがりで回数が空なら、予定の回数をそのまま使う
        if ($status === 'done' && $done <= 0) {
            $done = $planned;
        }

        $pdo = Db::conn();
        $pdo->beginTransaction();
        try {
            Db::exec(
                'INSERT INTO part_progress (target_week, part_id, planned_qty, done_qty, status, assignee, note, updated_by)
                 VALUES (?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE planned_qty = VALUES(planned_qty), done_qty = VALUES(done_qty),
                                         status = VALUES(status), assignee = VALUES(assignee),
                                         note = VALUES(note), updated_by = VALUES(updated_by)',
                [$week, $partId, $planned, $done, $status,
                 trim((string)($_POST['assignee'] ?? '')) ?: null,
                 trim((string)($_POST['note'] ?? '')) ?: null, Auth::id()]
            );

            Consumption::revert($week, $partId, Auth::id());
            $applied = $status === 'done'
                ? Consumption::apply($week, $partId, $done, Auth::id())
                : ['materials' => 0, 'short' => []];
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        OperationLog::write('update', 'part_progress', $week . '-' . $partId, '部位の進み具合を更新しました');
        if ($status === 'done') {
            $msg = '進み具合を更新し、' . $applied['materials'] . '材料を在庫から引きました。';
            if ($applied['short'] !== []) {
                $names = array_map(
                    static fn(array $s) => $s['name'] . '（' . View::num($s['qty'], 1) . '）',
                    array_slice($applied['short'], 0, 5)
                );
                $msg .= ' 在庫が足りず引けなかった分：' . implode('、', $names)
                    . (count($applied['short']) > 5 ? ' ほか' : '')
                    . '。材料の在庫で数量を確認してください。';
            }
            Session::flash('info', $msg);
        } else {
            Session::flash('info', '進み具合を更新しました。');
        }
        App::redirect('/progress?week=' . $week);
    }
}
