<?php
namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\OperationLog;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;

class ProductController
{
    /** 商品の一覧 */
    public static function index(): void
    {
        Auth::requireLogin();

        $products = Db::all(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM product_parts pp WHERE pp.product_id = p.id) AS part_count,
                    (SELECT COUNT(*) FROM product_materials pm WHERE pm.product_id = p.id) AS material_count
               FROM products p
              WHERE p.deleted_at IS NULL
              ORDER BY p.name'
        );

        View::render('products/index', ['products' => $products]);
    }

    /** 商品ごとの配合（レシピ） */
    public static function show(): void
    {
        Auth::requireLogin();

        $id = (int)($_GET['id'] ?? 0);
        $product = Db::one('SELECT * FROM products WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$product) {
            App::redirect('/products');
        }

        $parts = Db::all(
            'SELECT pp.*, pt.name AS part_name, pt.unit AS part_unit,
                    pt.batch_total_qty, pt.yield_rate, pt.round_batch, pt.is_shared,
                    (pp.fill_qty / pp.pieces_per_fill * pp.use_pieces) AS per_product_qty
               FROM product_parts pp
               JOIN parts pt ON pt.id = pp.part_id
              WHERE pp.product_id = ? AND pt.deleted_at IS NULL
              ORDER BY pp.sort_no, pt.name',
            [$id]
        );

        $materials = Db::all(
            'SELECT pm.*, m.name AS material_name, m.unit, m.maker_name, m.kind, m.is_supplied
               FROM product_materials pm
               JOIN materials m ON m.id = pm.material_id
              WHERE pm.product_id = ? AND m.deleted_at IS NULL
              ORDER BY pm.sort_no, m.name',
            [$id]
        );

        View::render('products/show', [
            'product'   => $product,
            'parts'     => $parts,
            'materials' => $materials,
        ]);
    }

    /** 商品の登録・修正フォーム */
    public static function edit(): void
    {
        Auth::requireLogin();
        if (!Auth::can('recipe')) {
            Session::flash('warn', 'この操作は管理者だけができます。');
            App::redirect('/products');
        }

        $id = (int)($_GET['id'] ?? 0);
        $product = $id > 0
            ? Db::one('SELECT * FROM products WHERE id = ? AND deleted_at IS NULL', [$id])
            : null;

        View::render('products/edit', ['product' => $product]);
    }

    /** 商品の保存 */
    public static function save(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('recipe')) {
            Session::flash('warn', 'この操作は管理者だけができます。');
            App::redirect('/products');
        }

        $id     = (int)($_POST['id'] ?? 0);
        $fields = ['name', 'seller_name', 'spec', 'shelf_life', 'thaw_shelf_life',
                   'launch_date', 'plan_qty_note', 'allergens', 'author', 'revised_at', 'note'];
        $data = [];
        foreach ($fields as $f) {
            $value = trim((string)($_POST[$f] ?? ''));
            $data[$f] = $value === '' ? null : $value;
        }
        $caseQty = ($_POST['case_qty'] ?? '') === '' ? null : (int)$_POST['case_qty'];

        $v = (new Validator())
            ->required($data['name'], '商品名')
            ->maxLength($data['name'], 100, '商品名');
        if ($v->fails()) {
            Session::flash('warn', implode(' ', $v->errors()));
            App::redirect($id > 0 ? '/products/edit?id=' . $id : '/products/edit');
        }

        if ($id > 0) {
            Db::exec(
                'UPDATE products SET name=?, seller_name=?, spec=?, shelf_life=?, thaw_shelf_life=?,
                        launch_date=?, plan_qty_note=?, allergens=?, author=?, revised_at=?, note=?,
                        case_qty=?, updated_by=?
                  WHERE id = ?',
                [...array_values($data), $caseQty, Auth::id(), $id]
            );
            OperationLog::write('update', 'products', (string)$id, '商品を修正しました：' . $data['name']);
            Session::flash('info', '商品を修正しました。');
        } else {
            $id = Db::insert(
                'INSERT INTO products (name, seller_name, spec, shelf_life, thaw_shelf_life,
                        launch_date, plan_qty_note, allergens, author, revised_at, note, case_qty, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [...array_values($data), $caseQty, Auth::id()]
            );
            OperationLog::write('create', 'products', (string)$id, '商品を登録しました：' . $data['name']);
            Session::flash('info', '商品を登録しました。つづけて使う部位を追加してください。');
        }

        App::redirect('/products/show?id=' . $id);
    }

    /** 商品に使う部位の追加・修正・削除 */
    public static function savePart(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('recipe')) {
            Session::flash('warn', 'この操作は管理者だけができます。');
            App::redirect('/products');
        }

        $productId = (int)($_POST['product_id'] ?? 0);
        $partId    = (int)($_POST['part_id'] ?? 0);

        if (($_POST['delete'] ?? '') === '1') {
            Db::exec('DELETE FROM product_parts WHERE product_id = ? AND part_id = ?', [$productId, $partId]);
            OperationLog::write('delete', 'product_parts', $productId . '-' . $partId, '商品から部位を外しました');
            Session::flash('info', '部位を外しました。');
            App::redirect('/products/show?id=' . $productId);
        }

        $fill     = (float)($_POST['fill_qty'] ?? 0);
        $perFill  = max(1, (int)($_POST['pieces_per_fill'] ?? 1));
        $usePcs   = max(1, (int)($_POST['use_pieces'] ?? 1));
        $note     = trim((string)($_POST['note'] ?? ''));

        $v = (new Validator())->number($_POST['fill_qty'] ?? '', '充填量')->positive($fill, '充填量');
        if ($partId <= 0 || $fill <= 0 || $v->fails()) {
            Session::flash('warn', '部位と充填量（0より大きい数）を入力してください。');
            App::redirect('/products/show?id=' . $productId);
        }

        Db::exec(
            'INSERT INTO product_parts (product_id, part_id, fill_qty, fill_unit, pieces_per_fill, use_pieces, note, sort_no)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE fill_qty = VALUES(fill_qty), pieces_per_fill = VALUES(pieces_per_fill),
                                     use_pieces = VALUES(use_pieces), note = VALUES(note)',
            [$productId, $partId, $fill, 'g', $perFill, $usePcs, $note === '' ? null : $note,
             (int)($_POST['sort_no'] ?? 0)]
        );
        OperationLog::write('update', 'product_parts', $productId . '-' . $partId, '商品の部位を登録しました');
        Session::flash('info', '部位を登録しました。');
        App::redirect('/products/show?id=' . $productId);
    }

    /** 商品に直接使う材料（1台あたり）の追加・修正・削除 */
    public static function saveMaterial(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('recipe')) {
            Session::flash('warn', 'この操作は管理者だけができます。');
            App::redirect('/products');
        }

        $productId  = (int)($_POST['product_id'] ?? 0);
        $materialId = (int)($_POST['material_id'] ?? 0);

        if (($_POST['delete'] ?? '') === '1') {
            Db::exec('DELETE FROM product_materials WHERE product_id = ? AND material_id = ?', [$productId, $materialId]);
            OperationLog::write('delete', 'product_materials', $productId . '-' . $materialId, '商品から材料を外しました');
            Session::flash('info', '材料を外しました。');
            App::redirect('/products/show?id=' . $productId);
        }

        $qty = (float)($_POST['qty'] ?? 0);
        if ($materialId <= 0 || $qty <= 0) {
            Session::flash('warn', '材料と数量（0より大きい数）を入力してください。');
            App::redirect('/products/show?id=' . $productId);
        }

        Db::exec(
            'INSERT INTO product_materials (product_id, material_id, qty, sort_no) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE qty = VALUES(qty)',
            [$productId, $materialId, $qty, (int)($_POST['sort_no'] ?? 0)]
        );
        OperationLog::write('update', 'product_materials', $productId . '-' . $materialId, '商品の直接材料を登録しました');
        Session::flash('info', '材料を登録しました。');
        App::redirect('/products/show?id=' . $productId);
    }
}
