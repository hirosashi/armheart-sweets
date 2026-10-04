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
 * 縦＝発注（得意先からの注文＝案件）、その下に作る商品ごとの部位の仕込み行、最後に材料の発注行。
 * 横＝日付（既定2週間、月曜始まり）。?job= で1件の発注だけを出す。
 */
class ScheduleController
{
    public const SPAN_OPTIONS = [7 => '1週間', 14 => '2週間', 21 => '3週間', 28 => '4週間'];

    /** 発注を追加するときの商品の入力行数 */
    public const ADD_ROWS = 5;

    public static function index(): void
    {
        Auth::requireLogin();

        $jobId  = (int)($_GET['job'] ?? 0);
        $single = $jobId > 0 ? Jobs::find($jobId) : null;
        if ($jobId > 0 && $single === null) {
            Session::flash('warn', 'その発注は見つかりません。');
            App::redirect('/schedule');
        }

        $span = (int)($_GET['span'] ?? 14);
        if (!isset(self::SPAN_OPTIONS[$span])) {
            $span = 14;
        }
        $fromIn = Clock::normalizeDate($_GET['from'] ?? null);
        if ($fromIn === null && $single !== null) {
            $fromIn = (string)Db::value(
                'SELECT LEAST(?, IFNULL(MIN(jp.target_date), ?)) FROM job_parts jp WHERE jp.job_id = ?',
                [$single['delivery_date'], $single['delivery_date'], $jobId]
            );
        }
        $from = Clock::weekStart($fromIn ?? Clock::today());
        $to   = Clock::rangeEnd($from, $span);
        $days = [];
        for ($i = 0; $i < $span; $i++) {
            $days[] = Clock::shiftDays($from, $i);
        }

        $onlyOpen = ($_GET['filter'] ?? 'all') === 'open';
        if ($single !== null) {
            unset($single['items']);
            $jobs = [$single];
        } else {
            $jobs = Jobs::inRange($from, $to);
            if ($onlyOpen) {
                $jobs = array_values(array_filter($jobs, fn($j) => $j['status'] === 'open'));
            }
        }
        $ids      = array_map(fn($j) => (int)$j['id'], $jobs);
        $items    = Jobs::items($ids);
        $partRows = Jobs::partRows($ids);
        $orders   = Jobs::purchaseOrders($ids);
        foreach ($jobs as $i => $j) {
            $jid   = (int)$j['id'];
            $parts = $partRows[$jid] ?? [];
            $its   = [];
            foreach ($items[$jid] ?? [] as $it) {
                $it['parts'] = array_values(array_filter($parts, fn($r) => (int)$r['job_item_id'] === (int)$it['id']));
                $it['ach']   = Jobs::achievement($it['parts']);
                $its[] = $it;
            }
            $jobs[$i]['items']  = $its;
            $jobs[$i]['parts']  = $parts;
            $jobs[$i]['orders'] = $orders[$jid] ?? [];
            $jobs[$i]['ach']    = Jobs::achievement($parts);
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
            'single'     => $single !== null ? $jobId : 0,
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
        $q = [];
        $job = (int)($_POST['back_job'] ?? 0);
        if ($job > 0) {
            $q['job'] = $job;
        }
        $from = Clock::normalizeDate($_POST['from'] ?? null);
        if ($from !== null) {
            $q['from'] = $from;
            $q['span'] = (int)($_POST['span'] ?? 14);
        }
        $edit = (int)($_POST['back_edit'] ?? 0);
        if ($edit > 0) {
            $q['edit'] = $edit;
        }
        return '/schedule' . ($q !== [] ? '?' . http_build_query($q) : '') . ($edit > 0 ? '#job-' . $edit : '');
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

    /** 仕上げ日の入力を整える（空・納品日より後なら 納品日の前日） */
    private static function finishDate(?string $finish, string $delivery): string
    {
        return $finish === null || $finish > $delivery
            ? Clock::shiftDays($delivery, -Jobs::FINISH_OFFSET)
            : $finish;
    }

    /** 発注（案件）の登録・修正。登録のときは作る商品（複数行）もいっしょに受け取る */
    public static function saveJob(): void
    {
        self::guard();
        $id       = (int)($_POST['id'] ?? 0);
        $customer = trim((string)($_POST['customer_name'] ?? ''));
        $titleIn  = trim((string)($_POST['title'] ?? ''));
        $delivery = Clock::normalizeDate($_POST['delivery_date'] ?? null);
        $note     = trim((string)($_POST['note'] ?? ''));
        $status   = (string)($_POST['status'] ?? 'open');

        $v = (new Validator())
            ->maxLength($customer, 100, '得意先')
            ->maxLength($titleIn, 100, '案件名')
            ->required($delivery, '納品日')
            ->maxLength($note, 255, 'メモ');

        $lines = [];
        if ($id <= 0) {
            $pids = (array)($_POST['product_id'] ?? []);
            foreach ($pids as $k => $pid) {
                $pid = (int)$pid;
                $qty = (int)(((array)($_POST['qty'] ?? []))[$k] ?? 0);
                if ($pid <= 0 && $qty <= 0) {
                    continue;
                }
                $lines[] = [$pid, $qty, Clock::normalizeDate(((array)($_POST['finish_date'] ?? []))[$k] ?? null)];
            }
            $bad = array_filter($lines, fn($l) => $l[0] <= 0 || $l[1] <= 0);
            $v->required($lines !== [] && $bad === [] ? '1' : '', '作る商品（商品と1以上の台数）');
        }
        if ($v->fails()) {
            Session::flash('warn', implode(' ', $v->errors()));
            App::redirect(self::back());
        }
        if (!isset(Jobs::STATUS_LABELS[$status])) {
            $status = 'open';
        }

        $pdo = Db::conn();
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                if (Jobs::find($id) === null) {
                    throw new \RuntimeException('その発注は見つかりません。');
                }
                Db::exec(
                    'UPDATE jobs SET customer_name=?, title=?, delivery_date=?, status=?, note=?, updated_by=? WHERE id=?',
                    [$customer !== '' ? $customer : null, $titleIn !== '' ? $titleIn : null, $delivery, $status,
                     $note !== '' ? $note : null, Auth::id(), $id]
                );
                $msg = '発注を直しました。';
                OperationLog::write('update', 'jobs', (string)$id, '発注（案件）を修正しました');
            } else {
                $id = Jobs::create([
                    'customer_name' => $customer !== '' ? $customer : null,
                    'title'         => $titleIn !== '' ? $titleIn : null,
                    'delivery_date' => $delivery,
                    'note'          => $note !== '' ? $note : null,
                ]);
                foreach ($lines as [$pid, $qty, $finish]) {
                    Jobs::addItem($id, $pid, $qty, self::finishDate($finish, $delivery));
                }
                $msg = '発注を追加し、商品ごとに部位の仕込みを仕上げ日の前日に仮置きしました。日や回数は「直す」で変えられます。';
                OperationLog::write('create', 'jobs', (string)$id, '発注（案件）を登録しました（商品' . count($lines) . '件）');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Session::flash('info', $msg);
        App::redirect(self::back());
    }

    /** 発注の作る商品の追加・修正・削除（台数0で削除）。商品・台数・仕上げ日が変わったら部位を仮置きし直す */
    public static function saveItem(): void
    {
        self::guard();
        $jobId     = (int)($_POST['job_id'] ?? 0);
        $itemId    = (int)($_POST['item_id'] ?? 0);
        $productId = (int)($_POST['product_id'] ?? 0);
        $qty       = (int)($_POST['qty'] ?? 0);
        $job       = Jobs::find($jobId);
        if ($job === null) {
            Session::flash('warn', 'その発注は見つかりません。');
            App::redirect(self::back());
        }
        $old = null;
        foreach ($job['items'] as $it) {
            if ((int)$it['id'] === $itemId) {
                $old = $it;
            }
        }
        if ($itemId > 0 && $old === null) {
            Session::flash('warn', 'その商品はこの発注にありません。');
            App::redirect(self::back());
        }
        if ($itemId > 0 && $qty <= 0) {
            Db::exec('DELETE FROM job_items WHERE id = ? AND job_id = ?', [$itemId, $jobId]);
            OperationLog::write('delete', 'job_items', (string)$itemId, '発注から商品を外しました');
            Session::flash('info', '商品「' . $old['product_name'] . '」を発注から外しました（その商品の仕込みも消えます）。');
            App::redirect(self::back());
        }
        $v = (new Validator())
            ->required($productId > 0 ? '1' : '', '商品')
            ->required($qty > 0 ? '1' : '', '台数（1以上）');
        if ($v->fails()) {
            Session::flash('warn', implode(' ', $v->errors()));
            App::redirect(self::back());
        }
        $finish = self::finishDate(Clock::normalizeDate($_POST['finish_date'] ?? null), $job['delivery_date']);

        $pdo = Db::conn();
        $pdo->beginTransaction();
        try {
            if ($old !== null) {
                Db::exec('UPDATE job_items SET product_id=?, qty=?, finish_date=? WHERE id=?',
                         [$productId, $qty, $finish, $itemId]);
                $changed = (int)$old['product_id'] !== $productId || (int)$old['qty'] !== $qty || $old['finish_date'] !== $finish;
                if ($changed) {
                    Jobs::placeParts($itemId);
                }
                $msg = $changed ? '商品を直し、部位の仕込みを仮置きし直しました。' : '変更はありませんでした。';
                OperationLog::write('update', 'job_items', (string)$itemId, '発注の商品を修正しました');
            } else {
                $itemId = Jobs::addItem($jobId, $productId, $qty, $finish);
                $msg = '商品を追加し、部位の仕込みを仕上げ日の前日に仮置きしました。';
                OperationLog::write('create', 'job_items', (string)$itemId, '発注に商品を追加しました');
            }
            Db::exec('UPDATE jobs SET updated_by = ? WHERE id = ?', [Auth::id(), $jobId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Session::flash('info', $msg);
        App::redirect(self::back());
    }

    /** 発注の削除（商品・仕込みの割り振りも消える。材料の発注は残す） */
    public static function deleteJob(): void
    {
        self::guard();
        $id = (int)($_POST['id'] ?? 0);
        Db::exec('UPDATE purchase_orders SET job_id = NULL WHERE job_id = ?', [$id]);
        Db::exec('DELETE FROM jobs WHERE id = ?', [$id]);
        OperationLog::write('delete', 'jobs', (string)$id, '発注（案件）を削除しました');
        Session::flash('info', '発注を削除しました。');
        $_POST['back_job'] = 0;
        $_POST['back_edit'] = 0;
        App::redirect(self::back());
    }

    /** 部位の仕込み行の保存（日・回数を手で直す／商品に部位を追加） */
    public static function savePart(): void
    {
        self::guard();
        $jobId   = (int)($_POST['job_id'] ?? 0);
        $itemId  = (int)($_POST['job_item_id'] ?? 0);
        $rowId   = (int)($_POST['row_id'] ?? 0);
        $partId  = (int)($_POST['part_id'] ?? 0);
        $date    = Clock::normalizeDate($_POST['target_date'] ?? null);
        $batches = (float)($_POST['batches'] ?? 0);

        $job = Jobs::find($jobId);
        $itemOk = $job !== null && in_array($itemId, array_map(fn($it) => (int)$it['id'], $job['items']), true);
        if (!$itemOk || $date === null || $partId <= 0) {
            Session::flash('warn', '商品・部位・仕込む日を確認してください。');
            App::redirect(self::back());
        }
        if ($batches <= 0) {
            if ($rowId > 0) {
                Db::exec('DELETE FROM job_parts WHERE id = ? AND job_id = ?', [$rowId, $jobId]);
                OperationLog::write('delete', 'job_parts', (string)$rowId, '部位の仕込み行を消しました');
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
                'INSERT INTO job_parts (job_id, job_item_id, part_id, target_date, batches) VALUES (?,?,?,?,?)',
                [$jobId, $itemId, $partId, $date, $batches]
            );
        }
        OperationLog::write('update', 'job_parts', (string)$jobId, '部位の仕込み日・回数を直しました');
        Session::flash('info', '仕込みの日・回数を保存しました。');
        App::redirect(self::back());
    }
}
