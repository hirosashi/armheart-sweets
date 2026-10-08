<?php
namespace App\Services;

use App\Controllers\MaterialController;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Db;

/**
 * 材料・仕入先・部位・商品と配合を、Excelのシート単位で書き出し・取り込みする。
 * 取り込みは呼び出し側のトランザクションの中で行い、errors が空のときだけ確定する。
 */
class MasterIo
{
    public const MAX_ERRORS = 100;

    private const GUIDE = '使い方';
    private const ON  = '○';
    private const OFF = '×';
    private const ON_WORDS  = ['○', '〇', '◯', '1', 'はい', 'する', 'あり'];
    private const OFF_WORDS = ['×', '✕', '0', 'いいえ', 'しない', 'なし', '-', '－'];

    private const SUPPLIER_TYPES = ['purchase' => '仕入先', 'sales' => '販売先', 'both' => '両方'];
    private const ORDER_METHODS  = ['fax' => 'FAX', 'email' => 'メール', 'tel' => '電話', 'web' => 'Web', 'other' => 'その他'];
    /** 以前の見出し名 => 今の見出し名（前に書き出したファイルも取り込めるように） */
    private const HEADER_ALIASES = ['仕入単位' => '荷姿', '仕入単位あたりの数量' => '荷姿あたりの数量'];

    /** 名前で参照する表と、その名前を登録するシート */
    private const REF_SHEETS = [
        'suppliers' => '仕入先',
        'materials' => '材料',
        'parts'     => '部位',
        'products'  => '商品',
    ];

    /** 取り込む順番（参照される側を先に） */
    private const ORDER = ['自社', '仕入先', '材料', '部位', '部位の配合', '商品', '商品の構成', '商品の配合'];

    /** 1行＝1件のシート。コード（入れた場合）か名前が同じ行は上書き、なければ追加 */
    private const ENTITIES = [
        '自社' => [
            'table' => 'companies', 'audit' => false, 'order' => 't.sort_no, t.id',
            'cols' => [
                ['h' => '自社名',       'c' => 'name',           't' => 'str', 'max' => 100, 'key' => true],
                ['h' => '郵便番号',     'c' => 'zip',            't' => 'str', 'max' => 10],
                ['h' => '住所',         'c' => 'address',        't' => 'str', 'max' => 255],
                ['h' => '電話',         'c' => 'tel',            't' => 'str', 'max' => 30],
                ['h' => 'FAX',          'c' => 'fax',            't' => 'str', 'max' => 30],
                ['h' => '納品場所',     'c' => 'delivery_place', 't' => 'str', 'max' => 255],
                ['h' => '発注書の既定', 'c' => 'is_default',     't' => 'bool', 'default' => 0],
                ['h' => '並び順',       'c' => 'sort_no',        't' => 'int', 'default' => 0],
            ],
        ],
        '仕入先' => [
            'table' => 'suppliers', 'audit' => true, 'order' => 't.name',
            'cols' => [
                ['h' => '仕入先コード',   'c' => 'code',           't' => 'str', 'max' => 20, 'code' => true],
                ['h' => '仕入先名',       'c' => 'name',           't' => 'str', 'max' => 100, 'key' => true],
                ['h' => '郵便番号',       'c' => 'zip',            't' => 'str', 'max' => 10],
                ['h' => '住所',           'c' => 'address',        't' => 'str', 'max' => 255],
                ['h' => '電話',           'c' => 'tel',            't' => 'str', 'max' => 30],
                ['h' => 'FAX',            'c' => 'fax',            't' => 'str', 'max' => 30],
                ['h' => '担当者名',       'c' => 'contact_name',   't' => 'str', 'max' => 100],
                ['h' => 'メール',         'c' => 'email',          't' => 'str', 'max' => 255],
                ['h' => '区分',           'c' => 'supplier_type',  't' => 'enum', 'map' => self::SUPPLIER_TYPES, 'default' => 'purchase'],
                ['h' => '発注方法',       'c' => 'order_method',   't' => 'enum', 'map' => self::ORDER_METHODS, 'default' => 'fax'],
                ['h' => '納品までの日数', 'c' => 'lead_time_days', 't' => 'int'],
                ['h' => 'メモ',           'c' => 'note',           't' => 'str', 'max' => 10000],
            ],
        ],
        '材料' => [
            'table' => 'materials', 'audit' => true, 'order' => 't.name',
            'cols' => [
                ['h' => '材料コード',               'c' => 'code',              't' => 'str', 'max' => 20, 'code' => true],
                ['h' => '材料名',                   'c' => 'name',              't' => 'str', 'max' => 100, 'key' => true],
                ['h' => '別の呼び方',               'c' => 'alias_names',       't' => 'str', 'max' => 500],
                ['h' => '種類',                     'c' => 'kind',              't' => 'enum', 'map' => MaterialController::KIND_LABELS, 'default' => 'material'],
                ['h' => '種別',                     'c' => 'category',          't' => 'str', 'max' => 50],
                ['h' => 'メーカー',                 'c' => 'maker_name',        't' => 'str', 'max' => 200],
                ['h' => 'アレルゲン',               'c' => 'allergens',         't' => 'str', 'max' => 255],
                ['h' => '単位',                     'c' => 'unit',              't' => 'str', 'max' => 20, 'default' => 'g'],
                ['h' => '荷姿',                     'c' => 'purchase_unit',     't' => 'str', 'max' => 20],
                ['h' => '荷姿あたりの数量',         'c' => 'purchase_qty',      't' => 'dec'],
                ['h' => '仕入先名',                 'c' => 'supplier_id',       't' => 'ref', 'ref' => 'suppliers'],
                ['h' => '支給品',                   'c' => 'is_supplied',       't' => 'bool', 'default' => 0],
                ['h' => '在庫管理',                 'c' => 'is_stock_managed',  't' => 'bool', 'default' => 1],
                ['h' => '賞味期限の管理',           'c' => 'has_expiry',        't' => 'bool', 'default' => 1],
                ['h' => '期限が近いと知らせる日数', 'c' => 'expiry_alert_days', 't' => 'int', 'default' => 5],
                ['h' => '余裕の倍率',               'c' => 'safety_ratio',      't' => 'dec', 'default' => 1.2, 'gt0' => true],
                ['h' => 'メモ',                     'c' => 'note',              't' => 'str', 'max' => 10000],
            ],
        ],
        '部位' => [
            'table' => 'parts', 'audit' => true, 'order' => 't.name',
            'cols' => [
                ['h' => '部位コード',     'c' => 'code',        't' => 'str', 'max' => 20, 'code' => true],
                ['h' => '部位名',         'c' => 'name',        't' => 'str', 'max' => 100, 'key' => true],
                ['h' => '単位',           'c' => 'unit',        't' => 'str', 'max' => 20, 'default' => 'g'],
                ['h' => '歩留まり',       'c' => 'yield_rate',  't' => 'dec', 'default' => 0.9, 'gt0' => true, 'max' => 1],
                ['h' => '回数を切り上げ', 'c' => 'round_batch', 't' => 'bool', 'default' => 1],
                ['h' => '共通の部位',     'c' => 'is_shared',   't' => 'bool', 'default' => 0],
                ['h' => 'メモ',           'c' => 'note',        't' => 'str', 'max' => 10000],
            ],
        ],
        '商品' => [
            'table' => 'products', 'audit' => true, 'order' => 't.name',
            'cols' => [
                ['h' => '商品コード',       'c' => 'code',            't' => 'str', 'max' => 20, 'code' => true],
                ['h' => '商品名',           'c' => 'name',            't' => 'str', 'max' => 100, 'key' => true],
                ['h' => '種類',             'c' => 'category',        't' => 'str', 'max' => 50],
                ['h' => '販売者',           'c' => 'seller_name',     't' => 'str', 'max' => 100],
                ['h' => '規格・形状',       'c' => 'spec',            't' => 'str', 'max' => 100],
                ['h' => '賞味期限',         'c' => 'shelf_life',      't' => 'str', 'max' => 100],
                ['h' => '解凍後消費期限',   'c' => 'thaw_shelf_life', 't' => 'str', 'max' => 100],
                ['h' => '導入予定日',       'c' => 'launch_date',     't' => 'date'],
                ['h' => '数量（限定数）',   'c' => 'plan_qty_note',   't' => 'str', 'max' => 100],
                ['h' => 'アレルゲン',       'c' => 'allergens',       't' => 'str', 'max' => 255],
                ['h' => '配合表の作成者',   'c' => 'author',          't' => 'str', 'max' => 50],
                ['h' => '配合表の改訂日',   'c' => 'revised_at',      't' => 'date'],
                ['h' => '入り数',           'c' => 'case_qty',        't' => 'int'],
                ['h' => 'ケース重量(g)',    'c' => 'case_weight',     't' => 'dec'],
                ['h' => 'ケース寸法',       'c' => 'case_size',       't' => 'str', 'max' => 50],
                ['h' => '使用中',           'c' => 'is_active',       't' => 'bool', 'default' => 1],
                ['h' => 'メモ',             'c' => 'note',            't' => 'str', 'max' => 10000],
            ],
        ],
    ];

    /** 配合・構成のシート。シートに出てくる親（部位・商品）ごとに中身を置きかえる */
    private const LINKS = [
        '部位の配合' => [
            'table'  => 'part_materials',
            'parent' => ['h' => '部位名', 'c' => 'part_id',     't' => 'ref', 'ref' => 'parts', 'key' => true],
            'child'  => ['h' => '材料名', 'c' => 'material_id', 't' => 'ref', 'ref' => 'materials', 'key' => true],
            'cols' => [
                ['h' => '配合量（1回の仕込み）', 'c' => 'qty',     't' => 'dec', 'key' => true, 'gt0' => true],
                ['h' => '並び順',                'c' => 'sort_no', 't' => 'int', 'default' => 0],
            ],
        ],
        '商品の構成' => [
            'table'  => 'product_parts',
            'parent' => ['h' => '商品名', 'c' => 'product_id', 't' => 'ref', 'ref' => 'products', 'key' => true],
            'child'  => ['h' => '部位名', 'c' => 'part_id',    't' => 'ref', 'ref' => 'parts', 'key' => true],
            'cols' => [
                ['h' => '充填量',         'c' => 'fill_qty',        't' => 'dec', 'key' => true, 'gt0' => true],
                ['h' => '充填量の単位',   'c' => 'fill_unit',       't' => 'str', 'max' => 20, 'default' => 'g'],
                ['h' => '取り数',         'c' => 'pieces_per_fill', 't' => 'int', 'default' => 1, 'gt0' => true],
                ['h' => '1台に使う個数',  'c' => 'use_pieces',      't' => 'int', 'default' => 1, 'gt0' => true],
                ['h' => 'メモ',           'c' => 'note',            't' => 'str', 'max' => 255],
                ['h' => '並び順',         'c' => 'sort_no',         't' => 'int', 'default' => 0],
            ],
        ],
        '商品の配合' => [
            'table'  => 'product_materials',
            'parent' => ['h' => '商品名', 'c' => 'product_id',  't' => 'ref', 'ref' => 'products', 'key' => true],
            'child'  => ['h' => '材料名', 'c' => 'material_id', 't' => 'ref', 'ref' => 'materials', 'key' => true],
            'cols' => [
                ['h' => '量（1台あたり）', 'c' => 'qty',     't' => 'dec', 'key' => true, 'gt0' => true],
                ['h' => '並び順',          'c' => 'sort_no', 't' => 'int', 'default' => 0],
            ],
        ],
    ];

    /** @var array<string, array<string, int>> 表 => 名前 => id */
    private static array $ids = [];

    /** @return list<string> */
    public static function sheetNames(): array
    {
        return self::ORDER;
    }

    /** @return array<string, int> シート名 => 今の登録数 */
    public static function counts(): array
    {
        $out = [];
        foreach (self::ORDER as $sheet) {
            $out[$sheet] = isset(self::ENTITIES[$sheet])
                ? (int)Db::value('SELECT COUNT(*) FROM ' . self::ENTITIES[$sheet]['table'] . ' WHERE deleted_at IS NULL')
                : (int)Db::value('SELECT COUNT(*) FROM ' . self::LINKS[$sheet]['table']);
        }
        return $out;
    }

    /** @return array<string, list<list<string|int|float|null>>> */
    public static function export(): array
    {
        $book = [self::GUIDE => self::guideRows()];
        foreach (self::ORDER as $sheet) {
            $book[$sheet] = isset(self::ENTITIES[$sheet])
                ? self::exportEntity(self::ENTITIES[$sheet])
                : self::exportLink(self::LINKS[$sheet]);
        }
        return $book;
    }

    /**
     * Excelの内容を登録する。
     *
     * @param array<string, array<int, list<string>>> $book Xlsx::read() の結果
     * @return array{errors: list<array{sheet: string, row: int, message: string}>, summary: array<string, array{added: int, updated: int}>}
     */
    public static function import(array $book): array
    {
        self::$ids = [];
        $errors  = [];
        $summary = [];

        if (array_intersect(self::ORDER, array_keys($book)) === []) {
            $errors[] = ['sheet' => '', 'row' => 0,
                'message' => '取り込めるシート（' . implode('・', self::ORDER) . '）が見つかりません。この画面で書き出したExcelを使ってください。'];
            return ['errors' => $errors, 'summary' => $summary];
        }

        foreach (self::ORDER as $sheet) {
            if (!isset($book[$sheet]) || $book[$sheet] === []) {
                continue;
            }
            $summary[$sheet] = isset(self::ENTITIES[$sheet])
                ? self::importEntity($sheet, self::ENTITIES[$sheet], $book[$sheet], $errors)
                : self::importLink($sheet, self::LINKS[$sheet], $book[$sheet], $errors);
            if (count($errors) >= self::MAX_ERRORS) {
                break;
            }
        }
        return ['errors' => array_slice($errors, 0, self::MAX_ERRORS), 'summary' => $summary];
    }

    private static function exportEntity(array $def): array
    {
        $select = ['t.*'];
        foreach ($def['cols'] as $col) {
            if ($col['t'] === 'ref') {
                $select[] = "(SELECT r.name FROM {$col['ref']} r WHERE r.id = t.{$col['c']}) AS ref_{$col['c']}";
            }
        }
        $records = Db::all(
            'SELECT ' . implode(', ', $select) . " FROM {$def['table']} t WHERE t.deleted_at IS NULL ORDER BY {$def['order']}"
        );

        $rows = [array_column($def['cols'], 'h')];
        foreach ($records as $r) {
            $line = [];
            foreach ($def['cols'] as $col) {
                $line[] = self::cellOut($col, $col['t'] === 'ref' ? $r['ref_' . $col['c']] : $r[$col['c']]);
            }
            $rows[] = $line;
        }
        return $rows;
    }

    private static function exportLink(array $def): array
    {
        $p = $def['parent'];
        $c = $def['child'];
        $records = Db::all(
            "SELECT l.*, p.name AS parent_name, ch.name AS child_name
               FROM {$def['table']} l
               JOIN {$p['ref']} p  ON p.id  = l.{$p['c']}
               JOIN {$c['ref']} ch ON ch.id = l.{$c['c']}
              WHERE p.deleted_at IS NULL AND ch.deleted_at IS NULL
              ORDER BY p.name, l.sort_no, ch.name"
        );

        $rows = [[$p['h'], $c['h'], ...array_column($def['cols'], 'h')]];
        foreach ($records as $r) {
            $line = [$r['parent_name'], $r['child_name']];
            foreach ($def['cols'] as $col) {
                $line[] = self::cellOut($col, $r[$col['c']]);
            }
            $rows[] = $line;
        }
        return $rows;
    }

    private static function cellOut(array $col, $value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return match ($col['t']) {
            'int'   => (int)$value,
            'dec'   => (float)$value,
            'bool'  => (int)$value === 1 ? self::ON : self::OFF,
            'enum'  => $col['map'][$value] ?? (string)$value,
            default => (string)$value,
        };
    }

    /** @return array{added: int, updated: int} */
    private static function importEntity(string $sheet, array $def, array $rows, array &$errors): array
    {
        $count = ['added' => 0, 'updated' => 0];
        $idx = self::headerIndex($sheet, $rows, array_filter($def['cols'], static fn($c) => !empty($c['key'])), $errors);
        if ($idx === null) {
            return $count;
        }
        $headerRow = array_key_first($rows);
        $table = $def['table'];
        $seen  = [];

        foreach ($rows as $rowNo => $cells) {
            if ($rowNo === $headerRow || self::blank($cells)) {
                continue;
            }
            $vals = self::rowValues($sheet, $rowNo, $def['cols'], $idx, $cells, $errors);
            if ($vals === null) {
                continue;
            }

            $name = (string)$vals['name'];
            $code = $vals['code'] ?? null;
            foreach (['name' => $name, 'code' => $code] as $kind => $k) {
                if ($k === null) {
                    continue;
                }
                if (isset($seen[$kind][$k])) {
                    $errors[] = ['sheet' => $sheet, 'row' => $rowNo,
                        'message' => ($kind === 'name' ? '名前' : 'コード') . "「{$k}」が{$seen[$kind][$k]}行目にもあります。"];
                    continue 2;
                }
                $seen[$kind][$k] = $rowNo;
            }

            // 照合順序では「キユーピー」と「キューピー」が同じ扱いになるため、名前・コードは1文字ずつ比べる
            $id = null;
            if ($code !== null) {
                $id = Db::value("SELECT id FROM {$table} WHERE code = ? COLLATE utf8mb4_bin AND deleted_at IS NULL", [$code]);
            }
            $id ??= self::idOf($table, $name);

            try {
                if ($id !== null) {
                    $set    = array_map(static fn($c) => "{$c} = ?", array_keys($vals));
                    $params = array_values($vals);
                    if ($def['audit']) {
                        $set[]    = 'updated_by = ?';
                        $params[] = Auth::id();
                    }
                    $params[] = (int)$id;
                    Db::exec("UPDATE {$table} SET " . implode(', ', $set) . ' WHERE id = ?', $params);
                    self::$ids[$table][$name] = (int)$id;
                    $count['updated']++;
                } else {
                    if ($def['audit']) {
                        $vals['created_by'] = Auth::id();
                    }
                    $cols = array_keys($vals);
                    Db::exec(
                        "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
                        array_values($vals)
                    );
                    self::$ids[$table][$name] = (int)Db::conn()->lastInsertId();
                    $count['added']++;
                }
            } catch (\PDOException $e) {
                $errors[] = ['sheet' => $sheet, 'row' => $rowNo,
                    'message' => '登録できませんでした。コード・名前が、削除済みのものやほかの行と重なっていないか確かめてください。'];
            }
        }
        return $count;
    }

    /** @return array{added: int, updated: int} */
    private static function importLink(string $sheet, array $def, array $rows, array &$errors): array
    {
        $count = ['added' => 0, 'updated' => 0];
        $cols  = [$def['parent'], $def['child'], ...$def['cols']];
        $idx   = self::headerIndex($sheet, $rows, array_filter($cols, static fn($c) => !empty($c['key'])), $errors);
        if ($idx === null) {
            return $count;
        }
        $headerRow = array_key_first($rows);
        $pc = $def['parent']['c'];
        $cc = $def['child']['c'];
        $groups = [];
        $seen   = [];
        $failed = false;

        foreach ($rows as $rowNo => $cells) {
            if ($rowNo === $headerRow || self::blank($cells)) {
                continue;
            }
            $vals = self::rowValues($sheet, $rowNo, $cols, $idx, $cells, $errors);
            if ($vals === null) {
                $failed = true;
                continue;
            }
            $pair = $vals[$pc] . '-' . $vals[$cc];
            if (isset($seen[$pair])) {
                $errors[] = ['sheet' => $sheet, 'row' => $rowNo,
                    'message' => "同じ「{$def['parent']['h']}」と「{$def['child']['h']}」の組み合わせが{$seen[$pair]}行目にもあります。"];
                $failed = true;
                continue;
            }
            $seen[$pair] = $rowNo;
            $groups[$vals[$pc]][] = $vals;
        }
        if ($failed) {
            return $count;
        }

        foreach ($groups as $parentId => $list) {
            Db::exec("DELETE FROM {$def['table']} WHERE {$pc} = ?", [$parentId]);
            foreach ($list as $vals) {
                $names = array_keys($vals);
                Db::exec(
                    "INSERT INTO {$def['table']} (" . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')',
                    array_values($vals)
                );
                $count['added']++;
            }
            if ($def['table'] === 'part_materials') {
                Db::exec(
                    'UPDATE parts SET batch_total_qty = (SELECT IFNULL(SUM(qty),0) FROM part_materials WHERE part_id = ?) WHERE id = ?',
                    [$parentId, $parentId]
                );
            }
            $count['updated']++;
        }
        return $count;
    }

    /**
     * 1行目の見出しから「見出し => 列番号」を作る。必須の見出しがなければ null。
     *
     * @return array<string, int>|null
     */
    private static function headerIndex(string $sheet, array $rows, array $required, array &$errors): ?array
    {
        $header = $rows[array_key_first($rows)];
        $idx = [];
        foreach ($header as $i => $h) {
            $h = trim($h);
            $h = self::HEADER_ALIASES[$h] ?? $h;
            if ($h !== '' && !isset($idx[$h])) {
                $idx[$h] = $i;
            }
        }
        $missing = array_values(array_filter(array_column($required, 'h'), static fn($h) => !isset($idx[$h])));
        if ($missing !== []) {
            $errors[] = ['sheet' => $sheet, 'row' => (int)array_key_first($rows),
                'message' => '見出し「' . implode('」「', $missing) . '」がありません。1行目の見出しは書き出したときのままにしてください。'];
            return null;
        }
        return $idx;
    }

    /**
     * 1行ぶんを表の列 => 値 に直す。見出しのない列は含めない。まちがいがあれば null。
     *
     * @return array<string, mixed>|null
     */
    private static function rowValues(string $sheet, int $rowNo, array $cols, array $idx, array $cells, array &$errors): ?array
    {
        $vals = [];
        $ok   = true;
        foreach ($cols as $col) {
            if (!isset($idx[$col['h']])) {
                continue;
            }
            $raw   = trim((string)($cells[$idx[$col['h']]] ?? ''));
            $error = null;
            $value = self::convert($col, $raw, $error);
            if ($error !== null) {
                $errors[] = ['sheet' => $sheet, 'row' => $rowNo, 'message' => $error];
                $ok = false;
                continue;
            }
            $vals[$col['c']] = $value;
        }
        return $ok ? $vals : null;
    }

    private static function convert(array $col, string $raw, ?string &$error)
    {
        $h = $col['h'];
        if ($raw === '') {
            if (!empty($col['key'])) {
                $error = "「{$h}」を入れてください。";
            }
            return $col['default'] ?? null;
        }

        switch ($col['t']) {
            case 'str':
                if (mb_strlen($raw) > $col['max']) {
                    $error = "「{$h}」は{$col['max']}文字以内にしてください。";
                }
                return $raw;

            case 'int':
            case 'dec':
                $n = str_replace([',', '，'], '', mb_convert_kana($raw, 'n'));
                $isInt = $col['t'] === 'int';
                if (!is_numeric($n) || (float)$n < 0 || ($isInt && (float)$n != floor((float)$n))) {
                    $error = "「{$h}」は0以上の" . ($isInt ? '整数' : '数') . "で入れてください（「{$raw}」）。";
                    return null;
                }
                if (!empty($col['gt0']) && (float)$n <= 0) {
                    $error = "「{$h}」は0より大きい数で入れてください。";
                } elseif (isset($col['max']) && (float)$n > $col['max']) {
                    $error = "「{$h}」は{$col['max']}以下で入れてください（「{$raw}」）。";
                }
                return $isInt ? (int)$n : (float)$n;

            case 'bool':
                if (in_array($raw, self::ON_WORDS, true)) {
                    return 1;
                }
                if (in_array($raw, self::OFF_WORDS, true)) {
                    return 0;
                }
                $error = "「{$h}」は ○（する）か ×（しない）で入れてください（「{$raw}」）。";
                return null;

            case 'enum':
                if (isset($col['map'][$raw])) {
                    return $raw;
                }
                $key = array_search($raw, $col['map'], true);
                if ($key === false) {
                    $error = "「{$h}」は " . implode('・', $col['map']) . " のどれかにしてください（「{$raw}」）。";
                    return null;
                }
                return $key;

            case 'date':
                $date = is_numeric($raw)
                    ? Clock::shiftDays('1899-12-30', (int)$raw)
                    : Clock::normalizeDate(str_replace('.', '/', $raw));
                if ($date === null) {
                    $error = "「{$h}」は 2026-12-01 のような日付で入れてください（「{$raw}」）。";
                }
                return $date;

            case 'ref':
                $id = self::idOf($col['ref'], $raw);
                if ($id === null) {
                    $error = "{$h}「{$raw}」が登録されていません。先に「" . self::REF_SHEETS[$col['ref']] . '」シートに入れてください。';
                }
                return $id;
        }
        return $raw;
    }

    private static function idOf(string $table, string $name): ?int
    {
        if (!isset(self::$ids[$table])) {
            self::$ids[$table] = [];
            foreach (Db::all("SELECT id, name FROM {$table} WHERE deleted_at IS NULL ORDER BY id DESC") as $r) {
                self::$ids[$table][$r['name']] = (int)$r['id'];
            }
        }
        return self::$ids[$table][$name] ?? null;
    }

    private static function blank(array $cells): bool
    {
        foreach ($cells as $v) {
            if (trim((string)$v) !== '') {
                return false;
            }
        }
        return true;
    }

    /** 「使い方」シート（取り込みでは読まない） */
    private static function guideRows(): array
    {
        $rows = [['シート', '見出し', '入れ方']];
        foreach ([
            'システムの「Excelで取り込み・書き出し」画面で、このファイルを直して取り込めます。',
            '1行目の見出しは変えないでください。列の並びは変えてもかまいません。使わないシート・列は消してもかまいません。',
            'コード（入れた場合）か名前が同じ行は上書きし、ない行は新しく追加します。Excelにない行は消えません。',
            '「部位の配合」「商品の構成」「商品の配合」は、シートに出てくる部位・商品ごとに、配合をシートの内容に置きかえます。',
            '名前で指定する欄（仕入先名・部位名・材料名・商品名）は、それぞれのシートに登録した名前と1文字ちがわずに入れてください。',
            'まちがいが1か所でもあると何も登録せず、画面にまちがいの一覧を出します。',
        ] as $line) {
            $rows[] = ['（全体）', '', $line];
        }
        foreach (self::ORDER as $sheet) {
            $cols = isset(self::ENTITIES[$sheet])
                ? self::ENTITIES[$sheet]['cols']
                : [self::LINKS[$sheet]['parent'], self::LINKS[$sheet]['child'], ...self::LINKS[$sheet]['cols']];
            foreach ($cols as $col) {
                $rows[] = [$sheet, $col['h'], self::describe($col)];
            }
        }
        return $rows;
    }

    private static function describe(array $col): string
    {
        $text = match ($col['t']) {
            'str'   => "文字（{$col['max']}文字まで）",
            'int'   => '0以上の整数',
            'dec'   => '0以上の数' . (isset($col['max']) ? "（{$col['max']}まで）" : ''),
            'bool'  => '○＝する／×＝しない',
            'enum'  => implode('／', $col['map']) . ' のどれか',
            'date'  => '2026-12-01 のような日付',
            'ref'   => '「' . self::REF_SHEETS[$col['ref']] . '」シートに登録した名前',
            default => '',
        };
        if (!empty($col['key'])) {
            $text = '必ず入れる。' . $text;
        }
        if (!empty($col['code'])) {
            $text .= '。入れた場合はコードで同じものを探す';
        }
        if (isset($col['default'])) {
            $d = $col['t'] === 'bool' ? ((int)$col['default'] === 1 ? self::ON : self::OFF)
                : ($col['t'] === 'enum' ? $col['map'][$col['default']] : (string)$col['default']);
            $text .= "。空欄なら {$d}";
        }
        return $text;
    }
}
