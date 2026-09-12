<?php
namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 日付・時刻の処理をこのクラス1本に集約する。
 *
 * ルール（重要）
 *  1. 時刻を扱うときは必ず Clock を通す。
 *     PHP の date() / time() / new DateTime() / strtotime() を
 *     アプリの各所で直接使わない。
 *  2. DBに保存する文字列は Clock::now() を使う（日本時間の 'Y-m-d H:i:s'）。
 *  3. SQL 側で NOW() や CURRENT_TIMESTAMP を使う場合も、接続時に
 *     Clock::MYSQL_OFFSET でセッションのタイムゾーンを日本時間へ固定するため、
 *     PHP 側と同じ時刻になる。
 *  4. 画面表示は Clock::dt() / Clock::d() / Clock::t() を使う。
 */
class Clock
{
    /** アプリ全体で使うタイムゾーン（日本時間） */
    public const TIMEZONE = 'Asia/Tokyo';

    /** MySQL のセッションタイムゾーンに設定する値（日本時間の固定オフセット） */
    public const MYSQL_OFFSET = '+09:00';

    /** DBに入れる日時の形 */
    public const DB_DATETIME = 'Y-m-d H:i:s';

    /** DBに入れる日付の形 */
    public const DB_DATE = 'Y-m-d';

    /** 画面表示の形 */
    public const VIEW_DATETIME = 'Y/m/d H:i';
    public const VIEW_DATE     = 'Y/m/d';
    public const VIEW_TIME     = 'H:i';

    /** テスト等で時刻を固定したいときに使う（未設定なら実時刻） */
    private static ?DateTimeImmutable $fixedNow = null;

    private static ?DateTimeZone $tz = null;

    /** 起動時に1回だけ呼ぶ。PHP側のタイムゾーンを日本時間へ固定する。 */
    public static function init(): void
    {
        date_default_timezone_set(self::TIMEZONE);
        ini_set('date.timezone', self::TIMEZONE);
    }

    public static function tz(): DateTimeZone
    {
        if (self::$tz === null) {
            self::$tz = new DateTimeZone(self::TIMEZONE);
        }
        return self::$tz;
    }

    /** 現在時刻（日本時間） */
    public static function nowObject(): DateTimeImmutable
    {
        if (self::$fixedNow !== null) {
            return self::$fixedNow;
        }
        return new DateTimeImmutable('now', self::tz());
    }

    /** 現在時刻の文字列。DBへ保存するときはこれを使う。 */
    public static function now(): string
    {
        return self::nowObject()->format(self::DB_DATETIME);
    }

    /** 今日の日付（'Y-m-d'） */
    public static function today(): string
    {
        return self::nowObject()->format(self::DB_DATE);
    }

    /** 現在のUNIX時刻（セッションの経過時間の判定などに使う） */
    public static function timestamp(): int
    {
        return self::nowObject()->getTimestamp();
    }

    /** 何分か前の時刻（ログイン失敗の集計などに使う） */
    public static function minutesAgo(int $minutes): string
    {
        return self::nowObject()
            ->modify('-' . $minutes . ' minutes')
            ->format(self::DB_DATETIME);
    }

    /** 何日か先の日付（賞味期限の判定などに使う） */
    public static function daysLater(int $days): string
    {
        return self::nowObject()
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')
            ->format(self::DB_DATE);
    }

    /** その週の月曜日（生産計画の週の基準日） */
    public static function weekStart(?string $date = null): string
    {
        $d = self::parse($date) ?? self::nowObject();
        if ((int)$d->format('N') !== 1) {
            $d = $d->modify('last monday');
        }
        return $d->format(self::DB_DATE);
    }

    /** 週の基準日を移動する（先週・来週の切り替え） */
    public static function shiftWeek(string $weekStart, int $weeks): string
    {
        $d = self::parse($weekStart);
        if ($d === null) {
            return self::weekStart();
        }
        return $d->modify(($weeks >= 0 ? '+' : '') . $weeks . ' weeks')->format(self::DB_DATE);
    }

    /**
     * DBから取り出した日時文字列などを日本時間として解釈する。
     * 解釈できない値・空値は null を返す（画面側で空欄表示にできる）。
     */
    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            return new DateTimeImmutable($value, self::tz());
        } catch (\Exception $e) {
            return null;
        }
    }

    /** 画面表示用の日時（例 2026/07/07 10:30） */
    public static function dt(?string $value): string
    {
        $d = self::parse($value);
        return $d === null ? '' : $d->format(self::VIEW_DATETIME);
    }

    /** 画面表示用の日付（例 2026/07/07） */
    public static function d(?string $value): string
    {
        $d = self::parse($value);
        return $d === null ? '' : $d->format(self::VIEW_DATE);
    }

    /** 画面表示用の時刻（例 10:30） */
    public static function t(?string $value): string
    {
        $d = self::parse($value);
        return $d === null ? '' : $d->format(self::VIEW_TIME);
    }

    /** 「7/7（火）」のような現場向けの表示 */
    public static function dayLabel(?string $value): string
    {
        $d = self::parse($value);
        if ($d === null) {
            return '';
        }
        $youbi = ['日', '月', '火', '水', '木', '金', '土'];
        return $d->format('n/j') . '（' . $youbi[(int)$d->format('w')] . '）';
    }

    /** 入力された日付が正しいか確認し、'Y-m-d' に整える。だめなら null。 */
    public static function normalizeDate(?string $value): ?string
    {
        $d = self::parse($value);
        return $d === null ? null : $d->format(self::DB_DATE);
    }

    /**
     * 今日から見て、その日付まであと何日かを返す（賞味期限の判定用）。
     * 過ぎている場合はマイナス。日付として解釈できないときは null。
     */
    public static function daysUntil(?string $date): ?int
    {
        $d = self::parse($date);
        if ($d === null) {
            return null;
        }
        $from = self::nowObject()->setTime(0, 0, 0);
        $to   = $d->setTime(0, 0, 0);
        return (int)$from->diff($to)->format('%r%a');
    }

    /** テスト用に時刻を固定する */
    public static function freeze(string $datetime): void
    {
        self::$fixedNow = new DateTimeImmutable($datetime, self::tz());
    }

    /** 固定を解除する */
    public static function unfreeze(): void
    {
        self::$fixedNow = null;
    }
}
