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
use App\Core\Xlsx;
use App\Services\OrderSheet;
use App\Services\Orders;

/** 原材料発注一覧（Excelと同じ並びのかんたん画面） */
class SheetController
{
    public static function index(): void
    {
        Auth::requireLogin();
        View::render('sheets/index', [
            'sheets'   => OrderSheet::all(),
            'products' => Db::all('SELECT id, name FROM products WHERE deleted_at IS NULL ORDER BY name'),
            'canEdit'  => Auth::can('order'),
        ]);
    }

    public static function create(): void
    {
        self::guard('/sheets');
        $productId = (int)($_POST['product_id'] ?? 0);
        $qty       = max(0, (int)($_POST['qty'] ?? 0));
        if (!Db::value('SELECT id FROM products WHERE id = ? AND deleted_at IS NULL', [$productId])) {
            Session::flash('warn', '商品を選んでください。');
            App::redirect('/sheets');
        }
        $id = Db::insert('INSERT INTO order_sheets (product_id, qty, created_by, updated_by) VALUES (?,?,?,?)',
                         [$productId, $qty, Auth::id(), Auth::id()]);
        OperationLog::write('create', 'order_sheets', (string)$id, '原材料発注一覧を作りました');
        App::redirect('/sheets/show?id=' . $id);
    }

    public static function show(): void
    {
        Auth::requireLogin();
        $sheet = OrderSheet::find((int)($_GET['id'] ?? 0));
        if ($sheet === null) {
            App::redirect('/sheets');
        }
        View::render('sheets/show', [
            'sheet'     => $sheet,
            'parts'     => OrderSheet::parts($sheet),
            'packs'     => OrderSheet::packs((int)$sheet['id']),
            'materials' => Db::all('SELECT name, kind FROM materials WHERE deleted_at IS NULL ORDER BY name'),
            'partNames' => Db::all('SELECT name FROM parts WHERE deleted_at IS NULL ORDER BY name'),
            'vendors'   => Db::all("SELECT DISTINCT v FROM (SELECT vendor_name AS v FROM materials WHERE vendor_name IS NOT NULL AND deleted_at IS NULL
                                    UNION SELECT vendor_name FROM purchase_orders WHERE vendor_name IS NOT NULL AND deleted_at IS NULL) t ORDER BY v"),
            'canEdit'   => Auth::can('order'),
        ]);
    }

    public static function export(): void
    {
        Auth::requireLogin();
        $sheet = OrderSheet::find((int)($_GET['id'] ?? 0));
        if ($sheet === null) {
            App::redirect('/sheets');
        }
        $parts = OrderSheet::parts($sheet);
        $packs = OrderSheet::packs((int)$sheet['id']);
        $path  = Xlsx::write(['原材料発注一覧' => OrderSheet::excelRows($sheet, $parts, $packs)]);
        $name  = '原材料発注一覧（' . $sheet['product_name'] . '）_' . str_replace('-', '', Clock::today()) . '.xlsx';
        OperationLog::write('export', 'order_sheets', (string)$sheet['id'], 'Excelに書き出しました');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"order_sheet.xlsx\"; filename*=UTF-8''" . rawurlencode($name));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        unlink($path);
        exit;
    }

    /** 画面の内容をまとめて保存する（配合・材料・発注・資材） */
    public static function save(): void
    {
        $id    = (int)($_POST['id'] ?? 0);
        self::guard('/sheets/show?id=' . $id);
        $sheet = OrderSheet::find($id);
        if ($sheet === null) {
            App::redirect('/sheets');
        }
        $productId = (int)$sheet['product_id'];
        $partIds   = array_map('intval', array_column(
            Db::all('SELECT part_id FROM product_parts WHERE product_id = ?', [$productId]), 'part_id'));
        $warn = [];

        $pdo = Db::conn();
        $pdo->beginTransaction();
        try {
            Db::exec('UPDATE order_sheets SET qty = ?, note = ?, updated_by = ? WHERE id = ?',
                     [max(0, (int)($_POST['qty'] ?? 0)), self::str($_POST['note'] ?? '', 255), Auth::id(), $id]);

            // 1Bでできる数
            foreach ((array)($_POST['pieces'] ?? []) as $ppId => $v) {
                Db::exec('UPDATE product_parts SET batch_pieces = ? WHERE id = ? AND product_id = ?',
                         [self::num($v), (int)$ppId, $productId]);
            }
            foreach ((array)($_POST['remove_part'] ?? []) as $ppId => $on) {
                Db::exec('DELETE FROM product_parts WHERE id = ? AND product_id = ?', [(int)$ppId, $productId]);
            }

            // 材料（発注先・発注単位・単位・保管配送）
            foreach ((array)($_POST['mat'] ?? []) as $mid => $f) {
                $f = (array)$f;
                $storage = (string)($f['storage'] ?? '');
                Db::exec(
                    'UPDATE materials SET vendor_name = ?, storage = ?, purchase_qty = ?, purchase_unit = ?, updated_by = ?
                      WHERE id = ? AND deleted_at IS NULL',
                    [self::str($f['vendor'] ?? '', 100), in_array($storage, OrderSheet::STORAGES, true) ? $storage : null,
                     self::num($f['per'] ?? ''), self::str($f['unit'] ?? '', 20), Auth::id(), (int)$mid]
                );
            }

            // 1B配合量・原材料を外す・追加
            $touched = [];
            foreach ((array)($_POST['pm'] ?? []) as $partId => $mats) {
                $partId = (int)$partId;
                if (!in_array($partId, $partIds, true)) {
                    continue;
                }
                foreach ((array)$mats as $mid => $f) {
                    $f = (array)$f;
                    if (!empty($f['remove'])) {
                        Db::exec('DELETE FROM part_materials WHERE part_id = ? AND material_id = ?', [$partId, (int)$mid]);
                    } elseif (($q = self::num($f['qty'] ?? '')) !== null && $q > 0) {
                        Db::exec('UPDATE part_materials SET qty = ? WHERE part_id = ? AND material_id = ?', [$q, $partId, (int)$mid]);
                    }
                    $touched[$partId] = true;
                }
            }
            foreach ((array)($_POST['new_pm'] ?? []) as $partId => $f) {
                $partId = (int)$partId;
                $f = (array)$f;
                $name = self::str($f['name'] ?? '', 100);
                if ($name === null || !in_array($partId, $partIds, true)) {
                    continue;
                }
                $q = self::num($f['qty'] ?? '');
                if ($q === null || $q <= 0) {
                    $warn[] = '「' . $name . '」は1B配合量が空のため追加していません。';
                    continue;
                }
                $mid = self::materialId($name, 'material');
                Db::exec(
                    'INSERT INTO part_materials (part_id, material_id, qty, sort_no)
                     VALUES (?,?,?, (SELECT IFNULL(MAX(x.sort_no),0)+1 FROM part_materials x WHERE x.part_id = ?))
                     ON DUPLICATE KEY UPDATE qty = VALUES(qty)',
                    [$partId, $mid, $q, $partId]
                );
                $touched[$partId] = true;
            }
            foreach (array_keys($touched) as $partId) {
                Db::exec('UPDATE parts SET batch_total_qty = (SELECT IFNULL(SUM(qty),0) FROM part_materials WHERE part_id = ?) WHERE id = ?',
                         [$partId, $partId]);
            }

            // 部位を追加
            $newPart = self::str($_POST['new_part']['name'] ?? '', 100);
            if ($newPart !== null) {
                $partId = (int)(Db::value('SELECT id FROM parts WHERE name = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$newPart]) ?: 0);
                if ($partId === 0) {
                    $partId = Db::insert('INSERT INTO parts (name, unit, created_by) VALUES (?,?,?)', [$newPart, 'g', Auth::id()]);
                }
                if (in_array($partId, $partIds, true)) {
                    $warn[] = '部位「' . $newPart . '」はもう入っています。';
                } else {
                    Db::exec(
                        'INSERT INTO product_parts (product_id, part_id, fill_qty, fill_unit, pieces_per_fill, use_pieces, batch_pieces, sort_no)
                         VALUES (?,?,?,?,1,1,?, (SELECT IFNULL(MAX(x.sort_no),0)+1 FROM product_parts x WHERE x.product_id = ?))',
                        [$productId, $partId, 0, 'g', self::num($_POST['new_part']['pieces'] ?? ''), $productId]
                    );
                }
            }

            // 資材
            foreach ((array)($_POST['pack'] ?? []) as $spId => $f) {
                $f = (array)$f;
                if (!empty($f['remove'])) {
                    Db::exec('DELETE FROM order_sheet_packs WHERE id = ? AND sheet_id = ?', [(int)$spId, $id]);
                } else {
                    Db::exec('UPDATE order_sheet_packs SET purpose = ?, use_qty = ? WHERE id = ? AND sheet_id = ?',
                             [self::str($f['purpose'] ?? '', 100), self::num($f['use_qty'] ?? ''), (int)$spId, $id]);
                }
            }
            $packName = self::str($_POST['new_pack']['name'] ?? '', 100);
            if ($packName !== null) {
                $mid = self::materialId($packName, 'pack_product');
                Db::exec(
                    'INSERT INTO order_sheet_packs (sheet_id, material_id, purpose, use_qty, sort_no)
                     VALUES (?,?,?,?, (SELECT IFNULL(MAX(x.sort_no),0)+1 FROM order_sheet_packs x WHERE x.sheet_id = ?))
                     ON DUPLICATE KEY UPDATE purpose = VALUES(purpose), use_qty = VALUES(use_qty)',
                    [$id, $mid, self::str($_POST['new_pack']['purpose'] ?? '', 100), self::num($_POST['new_pack']['use_qty'] ?? ''), $id]
                );
            }

            // 今ある発注の修正（発注数を空・0にすると取消）
            foreach ((array)($_POST['order'] ?? []) as $orderId => $f) {
                self::updateOrder($id, (int)$orderId, (array)$f);
            }
            // 分けて発注（新しい行）
            foreach ((array)($_POST['new_order'] ?? []) as $key => $lines) {
                [$partId, $mid] = array_map('intval', explode('-', (string)$key) + [0, 0]);
                foreach ((array)$lines as $f) {
                    self::createOrder($id, $partId, $mid, (array)$f);
                }
            }

            // 「商品の構成」の充填量を 1Bでできる数 に合わせる（スケジュール・必要な材料の画面と数字をそろえる）
            Db::exec(
                'UPDATE product_parts pp JOIN parts p ON p.id = pp.part_id
                    SET pp.fill_qty = ROUND(p.batch_total_qty * p.yield_rate * pp.pieces_per_fill / (pp.batch_pieces * pp.use_pieces), 3)
                  WHERE pp.product_id = ? AND pp.batch_pieces > 0 AND p.batch_total_qty > 0 AND pp.use_pieces > 0',
                [$productId]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        OperationLog::write('update', 'order_sheets', (string)$id, '原材料発注一覧を保存しました');
        Session::flash('info', '保存しました。材料・配合・発注の画面にも反映されています。');
        if ($warn !== []) {
            Session::flash('warn', implode(' ', $warn));
        }
        App::redirect('/sheets/show?id=' . $id);
    }

    private static function updateOrder(int $sheetId, int $orderId, array $f): void
    {
        $o = Db::one("SELECT * FROM purchase_orders WHERE id = ? AND sheet_id = ? AND deleted_at IS NULL AND status <> 'canceled'",
                     [$orderId, $sheetId]);
        if ($o === null) {
            return;
        }
        $qty = self::num($f['qty'] ?? '');
        if ($qty === null || $qty <= 0) {
            Db::exec("UPDATE purchase_orders SET status = 'canceled', updated_by = ? WHERE id = ?", [Auth::id(), $orderId]);
            OperationLog::write('update', 'purchase_orders', (string)$orderId, '原材料発注一覧で発注を取消にしました');
            return;
        }
        $orderDate = Clock::normalizeDate($f['order_date'] ?? null);
        $done      = !empty($f['done']);
        if ($done) {
            Db::exec('UPDATE purchase_order_items SET qty = ?, received_qty = ? WHERE order_id = ?', [$qty, $qty, $orderId]);
            $status = 'delivered';
        } else {
            Db::exec('UPDATE purchase_order_items SET qty = ?, received_qty = IF(received_qty >= qty, 0, LEAST(received_qty, ?)) WHERE order_id = ?',
                     [$qty, $qty, $orderId]);
            $status = Orders::statusFromItems($orderId);
            if ($status === 'ordered' && $orderDate === null && $o['status'] === 'draft') {
                $status = 'draft';
            }
        }
        Db::exec(
            'UPDATE purchase_orders SET status = ?, order_date = ?, desired_date = ?, vendor_name = ?,
                    delivered_date = CASE WHEN ? = \'delivered\' THEN IFNULL(delivered_date, ?) ELSE NULL END, updated_by = ?
              WHERE id = ?',
            [$status, $orderDate, Clock::normalizeDate($f['desired_date'] ?? null), self::vendorOf((int)Db::value(
                'SELECT material_id FROM purchase_order_items WHERE order_id = ? ORDER BY id LIMIT 1', [$orderId])) ?? $o['vendor_name'],
             $status, Clock::today(), Auth::id(), $orderId]
        );
    }

    private static function createOrder(int $sheetId, int $partId, int $mid, array $f): void
    {
        $qty = self::num($f['qty'] ?? '');
        if ($qty === null || $qty <= 0) {
            return;
        }
        $m = Db::one('SELECT id, name, supplier_id, vendor_name, purchase_unit FROM materials WHERE id = ? AND deleted_at IS NULL', [$mid]);
        if ($m === null) {
            return;
        }
        $company   = Db::one('SELECT id, delivery_place FROM companies ORDER BY is_default DESC, sort_no, id LIMIT 1');
        $orderDate = Clock::normalizeDate($f['order_date'] ?? null);
        $done      = !empty($f['done']);
        $status    = $done ? 'delivered' : ($orderDate !== null ? 'ordered' : 'draft');
        $orderId = Db::insert(
            'INSERT INTO purchase_orders (order_no, company_id, supplier_id, vendor_name, delivery_place, status, order_date,
                    sheet_id, sheet_part_id, desired_date, delivered_date, note, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [self::nextOrderNo(), $company['id'] ?? null, $m['supplier_id'] ?: null, $m['vendor_name'], $company['delivery_place'] ?? null,
             $status, $orderDate, $sheetId, $partId > 0 ? $partId : null, Clock::normalizeDate($f['desired_date'] ?? null),
             $done ? Clock::today() : null, '原材料発注一覧から作成', Auth::id()]
        );
        Db::exec(
            'INSERT INTO purchase_order_items (order_id, material_id, item_name, qty, unit, received_qty, sort_no) VALUES (?,?,?,?,?,?,1)',
            [$orderId, $mid, $m['name'], $qty, $m['purchase_unit'], $done ? $qty : 0]
        );
        OperationLog::write('create', 'purchase_orders', (string)$orderId, '原材料発注一覧から発注を作成しました');
    }

    private static function vendorOf(int $materialId): ?string
    {
        $v = Db::value('SELECT vendor_name FROM materials WHERE id = ?', [$materialId]);
        return $v !== null && $v !== '' ? (string)$v : null;
    }

    /** 名前が同じ材料があればそれを使い、なければ新しく登録する */
    private static function materialId(string $name, string $kind): int
    {
        $id = Db::value('SELECT id FROM materials WHERE name = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$name]);
        if ($id) {
            return (int)$id;
        }
        $id = Db::insert('INSERT INTO materials (name, kind, unit, note, created_by) VALUES (?,?,?,?,?)',
                         [$name, $kind, $kind === 'material' ? 'g' : '個', '原材料発注一覧で追加', Auth::id()]);
        OperationLog::write('create', 'materials', (string)$id, '原材料発注一覧で材料を登録しました：' . $name);
        return $id;
    }

    /** 発注番号（PO-YYYYMMDD-連番）。発注の管理と同じ形 */
    private static function nextOrderNo(): string
    {
        $prefix = 'PO-' . str_replace('-', '', Clock::today()) . '-';
        $count  = (int)Db::value('SELECT COUNT(*) FROM purchase_orders WHERE order_no LIKE ?', [$prefix . '%']);
        do {
            $no = $prefix . sprintf('%02d', ++$count);
        } while (Db::value('SELECT id FROM purchase_orders WHERE order_no = ?', [$no]));
        return $no;
    }

    private static function guard(string $back): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('order')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect($back);
        }
    }

    private static function str($v, int $max): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function num($v): ?float
    {
        $v = trim(str_replace([',', '，'], '', mb_convert_kana((string)$v, 'n')));
        return $v === '' || !is_numeric($v) ? null : (float)$v;
    }
}
