<?php
namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\OperationLog;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\Jobs;

/**
 * スケジュール（発注ごとのガントチャート）。
 * 縦＝発注（得意先からの注文：商品・台数・納品日）、その下に部位ごとの仕込み行と材料の発注行。
 * 横＝日付（既定2週間、月曜始まり）。
 */
class ScheduleController
{
    public const SPAN_OPTIONS = [7 => '1週間', 14 => '2週間', 21 => '3週間', 28 => '4週間'];

    public static function index(): void
    {
        Auth::requireLogin();

        $from = Clock::weekStart(Clock::normalizeDate($_GET['from'] ?? null) ?? Clock::today());
        $span = (int)($_GET['span'] ?? 14);
        if (!isset(self::SPAN_OPTIONS[$span])) {
            $span = 14;
        }
        $to   = Clock::rangeEnd($from, $span);
        $days = [];
        for ($i = 0; $i < $span; $i++) {
            $days[] = Clock::shiftDays($from, $i);
        }

        $onlyOpen = ($_GET['filter'] ?? 'all') === 'open';
        $jobs = Jobs::inRange($from, $to);
        if ($onlyOpen) {
            $jobs = array_values(array_filter($jobs, fn($j) => $j['status'] === 'open'));
        }
        $ids      = array_map(fn($j) => (int)$j['id'], $jobs);
        $partRows = Jobs::partRows($ids);
        $orders   = Jobs::purchaseOrders($ids);
        foreach ($jobs as $i => $j) {
            $jobs[$i]['parts']  = $partRows[(int)$j['id']] ?? [];
            $jobs[$i]['orders'] = $orders[(int)$j['id']] ?? [];
            $jobs[$i]['ach']    = Jobs::achievement($jobs[$i]['parts']);
        }

        View::render('schedule/index', [
            'from'       => $from,
            'to'         => $to,
            'span'       => $span,
            'days'       => $days,
            'today'      => Clock::today(),
            'prev_from'  => Clock::shiftWeek($from, -1),
            'next_from'  => Clock::shiftWeek($from, 1),
            'this_from'  => Clock::weekStart(Clock::today()),
            'only_open'  => $onlyOpen,
            'jobs'       => $jobs,
            'products'   => Db::all('SELECT id, name FROM products WHERE deleted_at IS NULL ORDER BY name, id'),
            'parts_all'  => Db::all('SELECT id, name FROM parts WHERE deleted_at IS NULL AND batch_total_qty > 0 ORDER BY name, id'),
            'edit_job'   => (int)($_GET['edit'] ?? 0),
            'add'        => isset($_GET['add']),
            'default_delivery' => Clock::shiftDays(Clock::today(), Jobs::FINISH_OFFSET + Jobs::PREP_OFFSET + 1),
        ]);
    }

    private static function back(): string
    {
        $from = Clock::normalizeDate($_POST['from'] ?? null);
        $span = (int)($_POST['span'] ?? 14);
        return '/schedule' . ($from !== null ? '?from=' . $from . '&span=' . $span : '');
    }

    private static function guard(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('require')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect(self::back());
        }
    }

    /** 発注（つくる予定）の登録・修正 */
    public static function saveJob(): void
    {
        self::guard();
        $id        = (int)($_POST['id'] ?? 0);
        $customer  = trim((string)($_POST['customer_name'] ?? ''));
        $productId = (int)($_POST['product_id'] ?? 0);
        $qty       = (int)($_POST['qty'] ?? 0);
        $delivery  = Clock::normalizeDate($_POST['delivery_date'] ?? null);
        $finish    = Clock::normalizeDate($_POST['finish_date'] ?? null);
        $note      = trim((string)($_POST['note'] ?? ''));
        $status    = (string)($_POST['status'] ?? 'open');

        $v = (new Validator())
            ->maxLength($customer, 100, '得意先')
            ->required($productId > 0 ? '1' : '', '商品')
            ->required($qty > 0 ? '1' : '', '台数（1以上）')
            ->required($delivery, '納品日')
            ->maxLength($note, 255, 'メモ');
        if ($v->fails()) {
            Session::flash('warn', implode(' ', $v->errors()));
            App::redirect(self::back());
        }
        if ($finish === null || $finish > $delivery) {
            $finish = Clock::shiftDays($delivery, -Jobs::FINISH_OFFSET);
        }
        if (!isset(Jobs::STATUS_LABELS[$status])) {
            $status = 'open';
        }

        $pdo = Db::conn();
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $old = Jobs::find($id);
                if ($old === null) {
                    throw new \RuntimeException('その発注は見つかりません。');
                }
                Db::exec(
                    'UPDATE jobs SET customer_name=?, product_id=?, qty=?, delivery_date=?, finish_date=?, status=?, note=?, updated_by=?
                      WHERE id=?',
                    [$customer !== '' ? $customer : null, $productId, $qty, $delivery, $finish, $status,
                     $note !== '' ? $note : null, Auth::id(), $id]
                );
                // 商品・台数・仕上げ日が変わったら部位の割り振りを作り直す（手で直した日は消える）
                if ((int)$old['product_id'] !== $productId || (int)$old['qty'] !== $qty || $old['finish_date'] !== $finish) {
                    Jobs::placeParts($id, $productId, $qty, $finish);
                }
                $msg = '発注を直しました。';
                OperationLog::write('update', 'jobs', (string)$id, '発注（つくる予定）を修正しました');
            } else {
                $id = Jobs::create([
                    'customer_name' => $customer !== '' ? $customer : null,
                    'product_id'    => $productId,
                    'qty'           => $qty,
                    'delivery_date' => $delivery,
                    'finish_date'   => $finish,
                    'note'          => $note !== '' ? $note : null,
                ]);
                $msg = '発注を追加し、部位の仕込みを仕上げ日の前日に仮置きしました。日や回数は行の「直す」で変えられます。';
                OperationLog::write('create', 'jobs', (string)$id, '発注（つくる予定）を登録しました');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Session::flash('info', $msg);
        App::redirect(self::back());
    }

    /** 発注の削除（仕込みの割り振りも消える。材料の発注は残す） */
    public static function deleteJob(): void
    {
        self::guard();
        $id = (int)($_POST['id'] ?? 0);
        Db::exec('UPDATE purchase_orders SET job_id = NULL WHERE job_id = ?', [$id]);
        Db::exec('DELETE FROM jobs WHERE id = ?', [$id]);
        OperationLog::write('delete', 'jobs', (string)$id, '発注（つくる予定）を削除しました');
        Session::flash('info', '発注を削除しました。');
        App::redirect(self::back());
    }

    /** 部位の仕込み行の保存（日・回数を手で直す／行の追加） */
    public static function savePart(): void
    {
        self::guard();
        $jobId = (int)($_POST['job_id'] ?? 0);
        $rowId = (int)($_POST['row_id'] ?? 0);
        $partId = (int)($_POST['part_id'] ?? 0);
        $date   = Clock::normalizeDate($_POST['target_date'] ?? null);
        $batches = (float)($_POST['batches'] ?? 0);

        if (Jobs::find($jobId) === null || $date === null || $partId <= 0) {
            Session::flash('warn', '部位・仕込む日を確認してください。');
            App::redirect(self::back());
        }
        if ($batches <= 0) {
            if ($rowId > 0) {
                Db::exec('DELETE FROM job_parts WHERE id = ? AND job_id = ?', [$rowId, $jobId]);
                Session::flash('info', '仕込み行を消しました。');
            }
            App::redirect(self::back());
        }
        if ($rowId > 0) {
            Db::exec(
                'UPDATE job_parts SET part_id=?, target_date=?, batches=? WHERE id=? AND job_id=?',
                [$partId, $date, $batches, $rowId, $jobId]
            );
        } else {
            Db::exec(
                'INSERT INTO job_parts (job_id, part_id, target_date, batches) VALUES (?,?,?,?)',
                [$jobId, $partId, $date, $batches]
            );
        }
        OperationLog::write('update', 'job_parts', (string)$jobId, '部位の仕込み日・回数を直しました');
        Session::flash('info', '仕込みの日・回数を保存しました。');
        App::redirect(self::back());
    }
}
