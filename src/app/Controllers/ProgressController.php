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
use App\Services\Progress;
use App\Services\Requirement;

class ProgressController
{
    public const STATUS_LABELS = [
        'todo'  => 'これから',
        'doing' => '仕込み中',
        'done'  => 'できあがり',
    ];

    /** 部位の進み具合（その日につくる部位を、これから／仕込み中／できあがりで管理。前日の残りは引き継ぐ） */
    public static function index(): void
    {
        Auth::requireLogin();

        $date = Clock::normalizeDate($_GET['date'] ?? null) ?? Clock::today();

        $columns = ['todo' => [], 'doing' => [], 'done' => []];
        foreach (Progress::cards($date) as $card) {
            $card['batches'] = $card['planned'] + $card['carried'];
            $columns[$card['status']][] = $card;
        }

        View::render('progress/index', [
            'date'      => $date,
            'prev_date' => Clock::shiftDays($date, -1),
            'next_date' => Clock::shiftDays($date, 1),
            'columns'   => $columns,
            'has_plan'  => Requirement::plans($date, $date) !== [],
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

        $date   = Clock::normalizeDate($_POST['date'] ?? null) ?? Clock::today();
        $partId = (int)($_POST['part_id'] ?? 0);
        $status = (string)($_POST['status'] ?? 'todo');
        if ($partId <= 0 || !isset(self::STATUS_LABELS[$status])) {
            App::redirect('/progress?date=' . $date);
        }

        $planned = (float)($_POST['planned_qty'] ?? 0);
        $carried = Progress::carriedFor($date, $partId);
        $done    = (float)($_POST['done_qty'] ?? 0);
        if ($done < 0) {
            Session::flash('warn', 'できた回数は0以上で入力してください。');
            App::redirect('/progress?date=' . $date);
        }
        // できあがりで回数が空なら、予定＋引き継ぎの回数をそのまま使う
        if ($status === 'done' && $done <= 0) {
            $done = $planned + $carried;
        }

        $pdo = Db::conn();
        $pdo->beginTransaction();
        try {
            Db::exec(
                'INSERT INTO part_progress (target_date, part_id, planned_qty, carried_qty, done_qty, status, assignee, note, updated_by)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE planned_qty = VALUES(planned_qty), carried_qty = VALUES(carried_qty),
                                         done_qty = VALUES(done_qty),
                                         status = VALUES(status), assignee = VALUES(assignee),
                                         note = VALUES(note), updated_by = VALUES(updated_by)',
                [$date, $partId, $planned, $carried, $done, $status,
                 trim((string)($_POST['assignee'] ?? '')) ?: null,
                 trim((string)($_POST['note'] ?? '')) ?: null, Auth::id()]
            );

            Consumption::revert($date, $partId, Auth::id());
            $applied = $status === 'done'
                ? Consumption::apply($date, $partId, $done, Auth::id())
                : ['materials' => 0, 'short' => []];
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        OperationLog::write('update', 'part_progress', $date . '-' . $partId, '部位の進み具合を更新しました');
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
            $rest = max(0.0, $planned + $carried - $done);
            Session::flash('info', '進み具合を更新しました。'
                . ($rest > 0 ? ' 残り ' . View::num($rest, 0) . ' 回は翌日に引き継がれます。' : ''));
        }
        App::redirect('/progress?date=' . $date);
    }
}
