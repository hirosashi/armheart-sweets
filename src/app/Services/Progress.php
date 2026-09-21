<?php
namespace App\Services;

use App\Core\Clock;
use App\Core\Db;

/**
 * 部位の進み具合（日単位）。
 * その日の予定回数に、前日までに終わらなかった残り回数（引き継ぎ）を足して表示する。
 *   残り(D) = 保存済みの行があれば（できあがりなら 0、それ以外は 予定＋引き継ぎ−できた回数）
 *             なければ その日の予定回数 ＋ 残り(D−1)
 *   引き継ぎ(D) = 残り(D−1)
 * さかのぼるのは LOOKBACK 日まで。
 */
class Progress
{
    public const LOOKBACK = 14;

    /**
     * その日のカード一覧（part_id => 情報）。予定も引き継ぎも無い部位は含まない。
     * @return array<int, array{part_id:int, part_name:string, unit:string, planned:float, carried:float,
     *                          need_qty:float, done_qty:float, status:string, assignee:?string, note:?string, updated_at:?string}>
     */
    public static function cards(string $date): array
    {
        $from = Clock::shiftDays($date, -self::LOOKBACK);
        $prev = Clock::shiftDays($date, -1);

        $planned = [];   // [date][part_id] => batches
        $info    = [];   // part_id => name/unit/need
        foreach (Requirement::partsByDay($from, $date) as $r) {
            $pid = (int)$r['id'];
            $planned[$r['target_date']][$pid] = (float)$r['batches'];
            $info[$pid] = ['name' => $r['name'], 'unit' => $r['unit'],
                           'need_qty' => $r['target_date'] === $date ? (float)$r['need_qty'] : 0.0];
        }
        $saved = [];     // [date][part_id] => row
        foreach (Db::all(
            'SELECT pg.*, p.name, p.unit FROM part_progress pg JOIN parts p ON p.id = pg.part_id
              WHERE pg.target_date BETWEEN ? AND ?',
            [$from, $date]
        ) as $row) {
            $pid = (int)$row['part_id'];
            $saved[$row['target_date']][$pid] = $row;
            if (!isset($info[$pid])) {
                $info[$pid] = ['name' => $row['name'], 'unit' => $row['unit'], 'need_qty' => 0.0];
            }
        }

        $cards = [];
        foreach ($info as $pid => $meta) {
            $carried  = self::remain($prev, $pid, $planned, $saved, $from);
            $plan     = $planned[$date][$pid] ?? 0.0;
            $row      = $saved[$date][$pid] ?? null;
            if ($plan <= 0 && $carried <= 0 && $row === null) {
                continue;
            }
            $cards[$pid] = [
                'part_id'    => $pid,
                'part_name'  => $meta['name'],
                'unit'       => $meta['unit'],
                'planned'    => $plan,
                'carried'    => $carried,
                'need_qty'   => $meta['need_qty'],
                'done_qty'   => $row ? (float)$row['done_qty'] : 0.0,
                'status'     => $row['status'] ?? 'todo',
                'assignee'   => $row['assignee'] ?? null,
                'note'       => $row['note'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }
        uasort($cards, fn($a, $b) => strcmp($a['part_name'], $b['part_name']));
        return $cards;
    }

    /** その日から翌日へ引き継ぐ残り回数 */
    private static function remain(string $date, int $pid, array $planned, array $saved, string $floor): float
    {
        if ($date < $floor) {
            return 0.0;
        }
        $row = $saved[$date][$pid] ?? null;
        if ($row !== null) {
            if ($row['status'] === 'done') {
                return 0.0;
            }
            return max(0.0, (float)$row['planned_qty'] + (float)$row['carried_qty'] - (float)$row['done_qty']);
        }
        $plan = $planned[$date][$pid] ?? 0.0;
        return $plan + self::remain(Clock::shiftDays($date, -1), $pid, $planned, $saved, $floor);
    }

    /** 前日から引き継ぐ回数（保存時に carried_qty へ記録する） */
    public static function carriedFor(string $date, int $partId): float
    {
        $cards = self::cards($date);
        return $cards[$partId]['carried'] ?? 0.0;
    }

    /** 左メニュー用：その日のできあがり数／部位数と引き継ぎ件数 */
    public static function summary(string $date): array
    {
        $cards = self::cards($date);
        $done = 0;
        $carried = 0;
        foreach ($cards as $c) {
            if ($c['status'] === 'done') {
                $done++;
            }
            if ($c['carried'] > 0) {
                $carried++;
            }
        }
        return ['total' => count($cards), 'done' => $done, 'carried' => $carried];
    }

    /**
     * スケジュール用：期間内の日ごとのカード（引き継ぎ込み）。
     * @return array<string, array<int, array>>  date => cards
     */
    public static function cardsByDay(string $from, string $to): array
    {
        $out = [];
        for ($d = $from; $d <= $to; $d = Clock::shiftDays($d, 1)) {
            $out[$d] = self::cards($d);
        }
        return $out;
    }
}
