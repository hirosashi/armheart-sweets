<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Db;
use App\Core\OperationLog;

/**
 * Excel「原材料発注一覧」（部位名・発注先・原材料名…の表＋資材関連＋製造数とB数）を読み、
 * 商品・部位・配合・材料・発注・資材・在庫として登録する。
 * 列は見出しの名前で探すので、列の位置が多少ちがっても読める。
 */
class OrderSheetImport
{
    private const MAT_COLS = [
        'part' => '部位名', 'vendor' => '発注先', 'name' => '原材料名', 'batch' => '1B配合量', 'use' => '使用数',
        'per' => '発注単位', 'need' => '必要数', 'qty' => '発注数', 'unit' => '単位', 'od' => '発注日', 'dd' => '納品日',
        'storage' => '保管配送', 'stock' => '在庫',
    ];
    private const PACK_COLS = [
        'vendor' => '発注先', 'name' => '資材名', 'purpose' => '使用用途', 'use' => '使用数', 'qty' => '発注数',
        'per' => '発注単位', 'od' => '発注日', 'dd' => '納品日', 'stock' => '在庫',
    ];

    /**
     * Excelの内容を読み取る（DBには書かない）。
     * @param array<string, array<int, list<string>>> $book
     */
    public static function parse(array $book, string $fileName): array
    {
        foreach ($book as $sheetName => $rows) {
            $head = self::findRow($rows, ['部位名', '原材料名']);
            if ($head !== null) {
                return self::parseSheet((string)$sheetName, $rows, $head, $fileName);
            }
        }
        throw new \RuntimeException('「部位名」「原材料名」の見出しがあるシートが見つかりませんでした。原材料発注一覧のExcelか確かめてください。');
    }

    private static function parseSheet(string $sheetName, array $rows, int $head, string $fileName): array
    {
        $warn = [];
        $col  = self::columns($rows[$head], self::MAT_COLS);
        foreach (['part', 'name', 'batch', 'qty'] as $k) {
            if (!isset($col[$k])) {
                throw new \RuntimeException('見出し「' . self::MAT_COLS[$k] . '」が見つかりませんでした。');
            }
        }
        $stockLabel = isset($col['stock']) ? trim((string)$rows[$head][$col['stock']]) : '';

        // 上の見出し（タイトル・製造数：20,000台 など）
        $title = ''; $topQty = null;
        foreach ($rows as $no => $r) {
            if ($no >= $head) {
                break;
            }
            foreach ($r as $v) {
                $v = trim((string)$v);
                if ($v === '') {
                    continue;
                }
                if (str_contains($v, '製造数')) {
                    $topQty = self::number(preg_replace('/^.*製造数[:：]?/u', '', $v));
                } elseif ($title === '') {
                    $title = $v;
                }
            }
        }

        $packHead = null; $qtyRow = null;
        $nos = array_keys($rows);
        foreach ($nos as $no) {
            if ($no <= $head) {
                continue;
            }
            $cells = array_map(static fn($v) => self::norm((string)$v), $rows[$no]);
            if ($packHead === null && in_array('資材名', $cells, true)) {
                $packHead = $no;
            }
            if ($qtyRow === null && ($cells[$col['part']] ?? '') === '製造数') {
                $qtyRow = $no;
            }
        }
        $matEnd = $packHead ?? $qtyRow ?? (max($nos) + 1);

        // 原材料の表
        $parts = []; $p = -1; $vendor = null; $m = null;
        foreach ($nos as $no) {
            if ($no <= $head || $no >= $matEnd) {
                continue;
            }
            $r   = $rows[$no];
            $get = static fn(string $k) => isset($col[$k]) ? trim((string)($r[$col[$k]] ?? '')) : '';
            $partName = $get('part');
            if ($partName === '資材関連') {
                break;
            }
            if ($partName !== '') {
                $parts[] = ['name' => $partName, 'rows' => [], 'pieces' => null, 'excel_batches' => null, 'bought' => false];
                $p++;
                $m = null;
            }
            if ($p < 0) {
                continue;
            }
            if ($get('vendor') !== '') {
                $vendor = $get('vendor');
            }
            $name = $get('name');
            if ($name === '' && $partName !== '' && $get('qty') !== '') {
                // 部位をまるごと買う（4号茶スポンジの完成品など）
                $name = $partName . '（完成品）';
                $parts[$p]['bought'] = true;
            }
            $isOrderRow = false;
            if ($name !== '') {
                $parts[$p]['rows'][] = [
                    'name' => $name, 'vendor' => $vendor, 'batch' => self::number($get('batch')) ?? ($parts[$p]['bought'] ? 1.0 : null),
                    'use' => self::number($get('use')), 'per' => self::number($get('per')) ?? ($parts[$p]['bought'] ? 1.0 : null),
                    'unit' => self::packUnit($get('unit')) ?? ($parts[$p]['bought'] ? '個' : null),
                    'storage' => in_array($get('storage'), OrderSheet::STORAGES, true) ? $get('storage') : null,
                    'stock_text' => $get('stock'), 'bought' => $parts[$p]['bought'] && $get('batch') === '',
                    'total' => self::number($get('qty')), 'orders' => [],
                ];
                $m = count($parts[$p]['rows']) - 1;
                $isOrderRow = $get('od') !== '' || $get('dd') !== '' || self::rowDone($r);
            } else {
                $isOrderRow = true;
                if ($get('stock') !== '' && $m !== null && $parts[$p]['rows'][$m]['stock_text'] === '') {
                    $parts[$p]['rows'][$m]['stock_text'] = $get('stock');
                }
            }
            $qty = self::number($get('qty'));
            if ($m !== null && $isOrderRow && $qty !== null && $qty > 0) {
                $parts[$p]['rows'][$m]['orders'][] = self::order($qty, $get('od'), $get('dd'), self::rowDone($r), $warn, $no);
            }
        }
        // 行に発注数だけあって、分けた行が無い材料は、その1行を発注（未発注）として扱う
        foreach ($parts as &$part) {
            foreach ($part['rows'] as &$row) {
                if ($row['orders'] === [] && $row['total'] !== null && $row['total'] > 0) {
                    $row['orders'][] = ['qty' => $row['total'], 'od' => null, 'dd' => null, 'done' => false];
                }
            }
            unset($row);
        }
        unset($part);

        // 資材関連
        $packs = [];
        if ($packHead !== null) {
            $pc = self::columns($rows[$packHead], self::PACK_COLS);
            $vendor = null;
            foreach ($nos as $no) {
                if ($no <= $packHead || ($qtyRow !== null && $no >= $qtyRow)) {
                    continue;
                }
                $r   = $rows[$no];
                $get = static fn(string $k) => isset($pc[$k]) ? trim((string)($r[$pc[$k]] ?? '')) : '';
                if ($get('vendor') !== '') {
                    $vendor = $get('vendor');
                }
                if ($get('name') !== '') {
                    $packs[] = ['name' => $get('name'), 'vendor' => $vendor, 'purpose' => $get('purpose') ?: null,
                                'use' => self::number($get('use')), 'per' => $get('per') ?: null, 'stock_text' => $get('stock'), 'orders' => []];
                }
                $qty = self::number($get('qty'));
                if ($packs !== [] && $qty !== null && $qty > 0) {
                    $packs[count($packs) - 1]['orders'][] = self::order($qty, $get('od'), $get('dd'), self::rowDone($r), $warn, $no);
                }
            }
        }

        // 製造数と 1Bでできる数・B数
        $qty = null;
        if ($qtyRow !== null) {
            $c0 = $col['part'];
            $qty = self::number($rows[$qtyRow][$c0 + 1] ?? '');
            $last = null;
            foreach ($nos as $no) {
                if ($no <= $qtyRow) {
                    continue;
                }
                $r = $rows[$no];
                $label = self::norm((string)($r[$c0] ?? ''));
                $val   = self::number($r[$c0 + 1] ?? '');
                if ($label !== '' && preg_match('/^(.+?)1B$/u', $label, $mm)) {
                    $last = self::partIndex($parts, $mm[1]);
                    if ($last !== null) {
                        $parts[$last]['pieces'] = $val;
                    }
                } elseif ($label === '' && $last !== null && str_contains((string)($r[$c0 + 2] ?? ''), 'B') && $val !== null) {
                    $parts[$last]['excel_batches'] = $val;
                }
            }
        }
        if ($qty === null) {
            $qty = $topQty;
        } elseif ($topQty !== null && (int)$topQty !== (int)$qty) {
            $warn[] = '製造数が2か所でちがいます（上：' . number_format($topQty) . '、下：' . number_format($qty) . '）。下の ' . number_format($qty) . ' で取り込みます。';
        }
        $qty = (int)($qty ?? 0);

        // 1Bでできる数が無い部位は、Excelの使用数から割り出す（1台あたりで計算している行など）
        foreach ($parts as &$part) {
            $first = $part['rows'][0] ?? null;
            $b = ($first && $first['batch'] > 0 && $first['use'] > 0) ? round($first['use'] / $first['batch'], 3) : null;
            if ($part['excel_batches'] === null && $b !== null) {
                $part['excel_batches'] = $b;
            }
            if ($part['pieces'] === null) {
                if ($part['excel_batches'] > 0 && $qty > 0) {
                    $part['pieces'] = round($qty / $part['excel_batches'], 3);
                } else {
                    $part['pieces'] = 1.0;
                    $warn[] = '部位「' . $part['name'] . '」の 1Bでできる数 が分からないため、1 で取り込みます。取り込んだあと画面で直してください。';
                }
            }
            $sys = OrderSheet::batches($qty, (float)$part['pieces']);
            if ($part['excel_batches'] !== null && abs($sys - $part['excel_batches']) > 0.001) {
                $warn[] = '部位「' . $part['name'] . '」のB数：Excelは ' . (float)$part['excel_batches'] . 'B、システムの計算は ' . $sys
                        . 'B（製造数 ' . number_format($qty) . ' ÷ 1Bでできる数 ' . (float)$part['pieces'] . '）です。必要数はシステムの計算で出ます。';
            }
        }
        unset($part);

        $product = preg_replace('/[（(]全体[）)]$/u', '', trim($sheetName));
        return [
            'file' => $fileName, 'sheet' => $sheetName, 'title' => $title, 'product_name' => $product,
            'qty' => $qty, 'stock_label' => $stockLabel, 'parts' => $parts, 'packs' => $packs, 'warn' => $warn,
            'other_sheets' => null,
        ];
    }

    /** 取り込んだときに何が起きるかの一覧（新しく作るもの・在庫の変わり方） */
    public static function plan(array $d): array
    {
        $mats = self::materialIndex();
        $newMats = []; $stocks = []; $warn = []; $orders = 0;
        $seen = [];
        $add = static function (string $name, string $text, bool $bought, ?float $per, bool $isPack) use (&$mats, &$newMats, &$stocks, &$warn, &$seen) {
            $id = $mats[self::key($name)]['id'] ?? null;
            if ($id === null && !isset($seen[self::key($name)])) {
                $newMats[] = $name;
            }
            $seen[self::key($name)] = true;
            if ($text === '') {
                return;
            }
            $baseUnit = $id !== null ? $mats[self::key($name)]['unit'] : ($isPack ? ($name === '外箱' ? '個' : '枚') : ($bought ? '個' : 'g'));
            $perQty   = $per ?? ($id !== null ? $mats[self::key($name)]['purchase_qty'] : null);
            $v = self::stockValue($text, $baseUnit, $perQty);
            if ($v === null) {
                $warn[] = '在庫「' . $name . '：' . $text . '」は数に直せなかったため、取り込みません。';
                return;
            }
            $now = $id !== null ? (float)Db::value('SELECT IFNULL(SUM(qty),0) FROM inventory WHERE material_id = ?', [$id]) : 0.0;
            $stocks[self::key($name)] = ['name' => $name, 'text' => $text, 'now' => $now, 'after' => $v, 'unit' => $baseUnit];
        };
        foreach ($d['parts'] as $part) {
            foreach ($part['rows'] as $r) {
                $add($r['name'], $r['stock_text'], $r['bought'], $r['per'], false);
                $orders += count($r['orders']);
            }
        }
        foreach ($d['packs'] as $r) {
            $add($r['name'], $r['stock_text'], false, self::number($r['per']), true);
            $orders += count($r['orders']);
        }
        $parts = [];
        foreach ($d['parts'] as $part) {
            $pid = self::partId($part['name']);
            $parts[] = ['name' => $part['name'], 'exists' => $pid !== null, 'rows' => count($part['rows']),
                        'pieces' => $part['pieces'], 'batches' => OrderSheet::batches((int)$d['qty'], (float)$part['pieces'])];
        }
        return ['new_materials' => $newMats, 'stocks' => array_values($stocks), 'orders' => $orders, 'parts' => $parts,
                'warn' => array_merge($d['warn'], $warn)];
    }

    /** 登録する。呼び出し側でトランザクションを張ること。作った一覧のIDを返す */
    public static function run(array $d, int $productId, ?string $newProduct, bool $withStock): int
    {
        $uid = Auth::id();
        if ($productId <= 0) {
            $productId = Db::insert('INSERT INTO products (name, note, created_by, updated_by) VALUES (?,?,?,?)',
                                    [$newProduct, '原材料発注一覧のExcelから登録', $uid, $uid]);
            OperationLog::write('create', 'products', (string)$productId, 'Excelの取り込みで商品を登録しました：' . $newProduct);
        }
        $productName = (string)Db::value('SELECT name FROM products WHERE id = ?', [$productId]);
        $sheetId = Db::insert('INSERT INTO order_sheets (product_id, qty, note, created_by, updated_by) VALUES (?,?,?,?,?)',
                              [$productId, (int)$d['qty'], mb_substr($d['file'] . ' から取り込み', 0, 255), $uid, $uid]);

        $sort = (int)Db::value('SELECT IFNULL(MAX(sort_no),0) FROM product_parts WHERE product_id = ?', [$productId]);
        foreach ($d['parts'] as $part) {
            $lines = [];
            foreach ($part['rows'] as $r) {
                $mid = self::upsertMaterial($r['name'], $r['bought'] ? '個' : 'g', 'material', $r['vendor'], $r['storage'],
                                            $r['unit'], $r['per']);
                $lines[] = [$mid, (float)($r['batch'] ?? 0), $r];
            }
            $partId = self::partFor($part['name'], $productId, $productName, $part['bought'] ? '個' : 'g',
                                    array_sum(array_map(static fn($l) => $l[1], $lines)));
            Db::exec('DELETE FROM part_materials WHERE part_id = ?', [$partId]);
            foreach ($lines as $k => [$mid, $batch]) {
                Db::exec('INSERT INTO part_materials (part_id, material_id, qty, sort_no) VALUES (?,?,?,?)', [$partId, $mid, $batch, $k]);
            }
            $ppId = Db::value('SELECT id FROM product_parts WHERE product_id = ? AND part_id = ?', [$productId, $partId]);
            if ($ppId) {
                Db::exec('UPDATE product_parts SET batch_pieces = ? WHERE id = ?', [$part['pieces'], $ppId]);
            } else {
                Db::exec('INSERT INTO product_parts (product_id, part_id, fill_qty, fill_unit, pieces_per_fill, use_pieces, batch_pieces, sort_no)
                          VALUES (?,?,0,?,1,1,?,?)', [$productId, $partId, 'g', $part['pieces'], ++$sort]);
            }
            foreach ($lines as [$mid, , $r]) {
                foreach ($r['orders'] as $o) {
                    OrderSheet::createOrder($sheetId, $partId, $mid, $o['qty'], $o['od'], $o['dd'], $o['done']);
                }
            }
        }
        Db::exec(
            'UPDATE product_parts pp JOIN parts p ON p.id = pp.part_id
                SET pp.fill_qty = ROUND(p.batch_total_qty * p.yield_rate * pp.pieces_per_fill / (pp.batch_pieces * pp.use_pieces), 3)
              WHERE pp.product_id = ? AND pp.batch_pieces > 0 AND p.batch_total_qty > 0 AND pp.use_pieces > 0',
            [$productId]
        );

        foreach ($d['packs'] as $k => $r) {
            $kind = str_contains((string)$r['purpose'], '焼成') ? 'pack_production' : 'pack_product';
            $mid  = self::upsertMaterial($r['name'], $r['name'] === '外箱' ? '個' : '枚', $kind, $r['vendor'], null, null,
                                         self::number($r['per']));
            Db::exec('INSERT INTO order_sheet_packs (sheet_id, material_id, purpose, use_qty, sort_no) VALUES (?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE purpose = VALUES(purpose), use_qty = VALUES(use_qty)',
                     [$sheetId, $mid, $r['purpose'] === null ? null : mb_substr($r['purpose'], 0, 100), $r['use'], $k]);
            foreach ($r['orders'] as $o) {
                OrderSheet::createOrder($sheetId, 0, $mid, $o['qty'], $o['od'], $o['dd'], $o['done']);
            }
        }

        if ($withStock) {
            foreach (self::plan($d)['stocks'] as $s) {
                self::setStock((int)self::materialIndex()[self::key($s['name'])]['id'], (float)$s['after'],
                               'Excelの取り込み（' . ($d['stock_label'] ?: '在庫') . '）');
            }
        }
        OperationLog::write('import', 'order_sheets', (string)$sheetId, 'Excelから原材料発注一覧を取り込みました：' . $d['file']);
        return $sheetId;
    }

    /** 在庫を棚卸しとして合わせる（最初のロットを指定の数にし、ほかのロットは0にする） */
    private static function setStock(int $mid, float $target, string $note): void
    {
        $lots = Db::all('SELECT id, qty FROM inventory WHERE material_id = ? ORDER BY id', [$mid]);
        if ($lots === []) {
            $id = Db::insert('INSERT INTO inventory (material_id, lot_no, qty, updated_by) VALUES (?,?,?,?)',
                             [$mid, 'Excel取り込み', $target, Auth::id()]);
            Db::exec("INSERT INTO inventory_adjustments (inventory_id, material_id, before_qty, after_qty, reason, note, created_by)
                      VALUES (?,?,NULL,?,'stocktake',?,?)", [$id, $mid, $target, $note, Auth::id()]);
            return;
        }
        foreach ($lots as $k => $lot) {
            $after = $k === 0 ? $target : 0.0;
            if (abs((float)$lot['qty'] - $after) < 0.0005) {
                continue;
            }
            Db::exec('UPDATE inventory SET qty = ?, updated_by = ? WHERE id = ?', [$after, Auth::id(), $lot['id']]);
            Db::exec("INSERT INTO inventory_adjustments (inventory_id, material_id, before_qty, after_qty, reason, note, created_by)
                      VALUES (?,?,?,?,'stocktake',?,?)", [$lot['id'], $mid, $lot['qty'], $after, $note, Auth::id()]);
        }
    }

    /** 名前が同じ（空白・全角半角のちがいは無視）材料を使い、なければ登録する。Excelに値がある項目だけ上書きする */
    private static function upsertMaterial(string $name, string $unit, string $kind, ?string $vendor, ?string $storage,
                                           ?string $purchaseUnit, ?float $per): int
    {
        $found = self::materialIndex()[self::key($name)] ?? null;
        if ($found !== null) {
            $id = (int)$found['id'];
            Db::exec('UPDATE materials SET vendor_name = IFNULL(?, vendor_name), storage = IFNULL(?, storage),
                             purchase_unit = IFNULL(?, purchase_unit), purchase_qty = IFNULL(?, purchase_qty), updated_by = ?
                       WHERE id = ?', [$vendor, $storage, $purchaseUnit, $per, Auth::id(), $id]);
            return $id;
        }
        $next = 1 + (int)Db::value("SELECT IFNULL(MAX(CAST(SUBSTRING(code, 2) AS UNSIGNED)), 0) FROM materials WHERE code REGEXP '^M[0-9]+$'");
        $id = Db::insert(
            'INSERT INTO materials (code, name, kind, unit, purchase_unit, purchase_qty, vendor_name, storage, note, created_by, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [sprintf('M%03d', $next), mb_substr($name, 0, 100), $kind, $unit, $purchaseUnit, $per, $vendor, $storage,
             '原材料発注一覧のExcelから登録', Auth::id(), Auth::id()]
        );
        OperationLog::write('create', 'materials', (string)$id, 'Excelの取り込みで材料を登録しました：' . $name);
        self::$materials = null;
        return $id;
    }

    /** 同じ名前の部位があればそれを使う。ほかの商品で使っている部位なら「部位名（商品名）」で別に作る */
    private static function partFor(string $name, int $productId, string $productName, string $unit, float $total): int
    {
        $id = self::partId($name);
        if ($id !== null) {
            $others = (int)Db::value('SELECT COUNT(*) FROM product_parts WHERE part_id = ? AND product_id <> ?', [$id, $productId]);
            if ($others > 0) {
                $name = mb_substr($name . '（' . $productName . '）', 0, 100);
                $id   = self::partId($name);
            }
        }
        if ($id === null) {
            $id = Db::insert('INSERT INTO parts (name, unit, batch_total_qty, created_by, updated_by) VALUES (?,?,?,?,?)',
                             [$name, $unit, $total, Auth::id(), Auth::id()]);
            OperationLog::write('create', 'parts', (string)$id, 'Excelの取り込みで部位を登録しました：' . $name);
        } else {
            Db::exec('UPDATE parts SET batch_total_qty = ?, updated_by = ? WHERE id = ?', [$total, Auth::id(), $id]);
        }
        return $id;
    }

    private static function partId(string $name): ?int
    {
        foreach (Db::all('SELECT id, name FROM parts WHERE deleted_at IS NULL ORDER BY id') as $p) {
            if (self::key($p['name']) === self::key($name)) {
                return (int)$p['id'];
            }
        }
        return null;
    }

    private static ?array $materials = null;

    /** 正規化した名前（と別表記） => 材料 */
    private static function materialIndex(): array
    {
        if (self::$materials === null) {
            self::$materials = [];
            foreach (Db::all('SELECT id, name, alias_names, unit, purchase_qty FROM materials WHERE deleted_at IS NULL ORDER BY id') as $m) {
                foreach (array_merge([$m['name']], explode('/', (string)$m['alias_names'])) as $n) {
                    if (trim($n) !== '') {
                        self::$materials[self::key($n)] ??= $m;
                    }
                }
            }
        }
        return self::$materials;
    }

    private static function partIndex(array $parts, string $label): ?int
    {
        foreach ($parts as $i => $p) {
            if (self::key($p['name']) === self::key($label)) {
                return $i;
            }
        }
        return null;
    }

    private static function order(float $qty, string $od, string $dd, bool $done, array &$warn, int $rowNo): array
    {
        $o = ['qty' => $qty, 'od' => self::parseDate($od), 'dd' => self::parseDate($dd), 'done' => $done];
        foreach (['od' => $od, 'dd' => $dd] as $k => $raw) {
            if ($raw !== '' && $o[$k] === null) {
                $warn[] = $rowNo . '行目の日付「' . $raw . '」が読めなかったため、空で取り込みます。';
            }
        }
        return $o;
    }

    private static function rowDone(array $r): bool
    {
        foreach ($r as $v) {
            if (trim((string)$v) === '済') {
                return true;
            }
        }
        return false;
    }

    /** Excelの日付（シリアル値・2026/10/7・10/7） */
    private static function parseDate(string $v): ?string
    {
        $v = trim(mb_convert_kana($v, 'as'));
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d+(\.\d+)?$/', $v) && (float)$v > 20000 && (float)$v < 80000) {
            return Clock::shiftDays('1899-12-30', (int)$v);
        }
        if (preg_match('#^(\d{1,2})[/月](\d{1,2})日?$#u', $v, $m)) {
            $v = substr(Clock::today(), 0, 4) . '-' . $m[1] . '-' . $m[2];
        }
        return Clock::normalizeDate(str_replace(['/', '.'], '-', $v));
    }

    /** 「袋（25㎏）」→「袋」 */
    private static function packUnit(string $v): ?string
    {
        $v = trim((string)preg_replace('/[（(].*$/u', '', $v));
        return $v === '' ? null : mb_substr($v, 0, 20);
    }

    /**
     * 在庫の書き方（1,554g / 0.6缶 / 19C/S＋3760g / １袋（2.0kg） / 20.0kg / 1.26L / 3,900枚）を管理単位の数にする。
     * 荷姿の数（缶・袋・C/S など）は 荷姿あたりの数量 を掛ける。
     */
    public static function stockValue(string $text, string $unit, ?float $per): ?float
    {
        $t = str_replace([',', '，', ' ', '　'], '', mb_convert_kana($text, 'as'));
        $t = str_replace(['㎏', 'ｋｇ', 'KG', 'Kg'], 'kg', $t);
        if ($t === '') {
            return null;
        }
        $sum = 0.0;
        foreach (preg_split('/[+＋]/u', $t) as $term) {
            if (preg_match('/^([\d.]+)[^\d(（]*[(（]([\d.]+)(kg|g|L|l|ml)[)）]$/u', $term, $m)) {
                $sum += (float)$m[1] * self::toBase((float)$m[2], $m[3], $unit);
                continue;
            }
            if (!preg_match('/^([\d.]+)(.*)$/u', $term, $m) || !is_numeric($m[1])) {
                return null;
            }
            $n = (float)$m[1]; $u = $m[2];
            if ($u === '' || $u === $unit) {
                $sum += $n;
            } elseif (in_array($u, ['kg', 'g', 'L', 'l', 'ml'], true)) {
                $sum += self::toBase($n, $u, $unit);
            } elseif ($per !== null && $per > 0) {
                $sum += $n * $per;
            } else {
                return null;
            }
        }
        return round($sum, 3);
    }

    private static function toBase(float $n, string $u, string $unit): float
    {
        return in_array($u, ['kg', 'L', 'l'], true) && $unit !== '個' && $unit !== '枚' ? $n * 1000 : $n;
    }

    private static function columns(array $row, array $names): array
    {
        $col = [];
        foreach ($row as $i => $v) {
            $h = self::norm((string)$v);
            foreach ($names as $k => $label) {
                if (!isset($col[$k]) && $h !== '' && ($k === 'unit' ? $h === $label : str_starts_with($h, $label))) {
                    $col[$k] = $i;
                    break;
                }
            }
        }
        return $col;
    }

    private static function findRow(array $rows, array $labels): ?int
    {
        foreach ($rows as $no => $r) {
            $cells = array_map(static fn($v) => self::norm((string)$v), $r);
            if (count(array_intersect($labels, $cells)) === count($labels)) {
                return $no;
            }
        }
        return null;
    }

    private static function number($v): ?float
    {
        $v = trim(str_replace([',', '，'], '', mb_convert_kana((string)$v, 'as')));
        if (preg_match('/^-?[\d.]+/', $v, $m) && is_numeric($m[0])) {
            return (float)$m[0];
        }
        return null;
    }

    /** 見出し・名前の比べ方：全角半角・空白のちがいを無視 */
    private static function norm(string $s): string
    {
        return (string)preg_replace('/\s+/u', '', mb_convert_kana($s, 'asKV'));
    }

    private static function key(string $s): string
    {
        return mb_strtolower(self::norm($s));
    }
}
