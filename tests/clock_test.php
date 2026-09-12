<?php
/**
 * 時刻処理（App\Core\Clock）の確認。
 * 実行: php tests/clock_test.php
 */
declare(strict_types=1);

require __DIR__ . '/../src/app/bootstrap.php';

use App\Core\Clock;
use App\Core\Db;

$fail = 0;
$check = function (string $name, $actual, $expected) use (&$fail): void {
    $ok = $actual === $expected;
    if (!$ok) {
        $fail++;
    }
    printf("%s %s : %s%s\n", $ok ? 'OK  ' : 'NG  ', $name, var_export($actual, true),
        $ok ? '' : ' (期待値 ' . var_export($expected, true) . ')');
};

// タイムゾーンが日本時間で固定されているか
$check('date_default_timezone_get', date_default_timezone_get(), 'Asia/Tokyo');

// 固定時刻での書式・計算
Clock::freeze('2026-07-07 09:05:00');
$check('now',            Clock::now(),                 '2026-07-07 09:05:00');
$check('today',          Clock::today(),               '2026-07-07');
$check('dt',             Clock::dt('2026-07-07 09:05:00'), '2026/07/07 09:05');
$check('d',              Clock::d('2026-07-07 09:05:00'),  '2026/07/07');
$check('t',              Clock::t('2026-07-07 09:05:00'),  '09:05');
$check('dayLabel',       Clock::dayLabel('2026-07-07'),    '7/7（火）');
$check('minutesAgo(10)', Clock::minutesAgo(10),        '2026-07-07 08:55:00');
$check('daysLater(5)',   Clock::daysLater(5),          '2026-07-12');
$check('weekStart',      Clock::weekStart(),           '2026-07-06'); // 火曜→同週の月曜
$check('weekStart(月)',  Clock::weekStart('2026-07-06'), '2026-07-06');
$check('shiftWeek(+1)',  Clock::shiftWeek('2026-07-06', 1), '2026-07-13');
$check('daysUntil(明後日)', Clock::daysUntil('2026-07-09'), 2);
$check('daysUntil(前日)',   Clock::daysUntil('2026-07-06'), -1);
$check('parse(空)',      Clock::parse(''),             null);
$check('parse(0000)',    Clock::parse('0000-00-00 00:00:00'), null);
$check('dt(null)',       Clock::dt(null),              '');
$check('normalizeDate',  Clock::normalizeDate('2026/7/7'), '2026-07-07');
$check('normalizeDate(不正)', Clock::normalizeDate('あ'), null);
Clock::unfreeze();

// DBの時計がPHPと同じ日本時間か（1分以内の差なら一致とみなす）
try {
    $dbNow  = (string)Db::value('SELECT NOW()');
    $dbTz   = (string)Db::value('SELECT @@session.time_zone');
    $diff   = abs(strtotime($dbNow) - Clock::timestamp());
    $check('DBのセッションtime_zone', $dbTz, Clock::MYSQL_OFFSET);
    printf("%s DBとPHPの時刻差 : %d 秒 (PHP %s / DB %s)\n",
        $diff <= 60 ? 'OK  ' : 'NG  ', $diff, Clock::now(), $dbNow);
    if ($diff > 60) {
        $fail++;
    }
} catch (\Throwable $e) {
    echo "SKIP DB確認: " . $e->getMessage() . "\n";
}

echo $fail === 0 ? "\nすべて一致しました。\n" : "\n{$fail} 件が一致しません。\n";
exit($fail === 0 ? 0 : 1);
