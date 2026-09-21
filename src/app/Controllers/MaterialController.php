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

class MaterialController
{
    public const KIND_LABELS = [
        'material'        => '原材料',
        'pack_production' => '生産用の資材',
        'pack_product'    => '商品用の資材',
    ];

    /** 材料の一覧（登録されている材料をさがす） */
    public static function index(): void
    {
        Auth::requireLogin();

        $keyword = trim((string)($_GET['q'] ?? ''));
        $kind    = (string)($_GET['kind'] ?? '');

        $where  = ['m.deleted_at IS NULL'];
        $params = [];
        if ($keyword !== '') {
            $where[]  = '(m.name LIKE ? OR m.alias_names LIKE ? OR m.maker_name LIKE ?)';
            $like     = '%' . $keyword . '%';
            $params   = [$like, $like, $like];
        }
        if (isset(self::KIND_LABELS[$kind])) {
            $where[]  = 'm.kind = ?';
            $params[] = $kind;
        }
        $whereSql = implode(' AND ', $where);

        View::render('materials/index', [
            'materials' => Db::all(
                "SELECT m.*, s.name AS supplier_name,
                        (SELECT COUNT(*) FROM part_materials pm WHERE pm.material_id = m.id) AS used_parts
                   FROM materials m
              LEFT JOIN suppliers s ON s.id = m.supplier_id
                  WHERE {$whereSql}
                  ORDER BY m.name
                  LIMIT 500",
                $params
            ),
            'total'   => (int)Db::value("SELECT COUNT(*) FROM materials m WHERE {$whereSql}", $params),
            'all'     => (int)Db::value('SELECT COUNT(*) FROM materials WHERE deleted_at IS NULL'),
            'keyword' => $keyword,
            'kind'    => $kind,
        ]);
    }

    /** 材料の新規登録・修正フォーム */
    public static function edit(): void
    {
        Auth::requireLogin();
        if (!Auth::can('material')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect('/materials');
        }

        $id = (int)($_GET['id'] ?? 0);
        $material = $id > 0
            ? Db::one('SELECT * FROM materials WHERE id = ? AND deleted_at IS NULL', [$id])
            : null;
        if ($id > 0 && !$material) {
            App::redirect('/materials');
        }

        View::render('materials/edit', [
            'material'  => $material,
            'suppliers' => Db::all(
                "SELECT id, name FROM suppliers
                  WHERE deleted_at IS NULL AND supplier_type IN ('purchase','both')
                  ORDER BY name"
            ),
        ]);
    }

    /** 材料の保存 */
    public static function save(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('material')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect('/materials');
        }

        $id     = (int)($_POST['id'] ?? 0);
        $fields = ['name', 'alias_names', 'category', 'maker_name', 'allergens',
                   'purchase_unit', 'note'];
        $data = [];
        foreach ($fields as $f) {
            $value = trim((string)($_POST[$f] ?? ''));
            $data[$f] = $value === '' ? null : $value;
        }
        $kind        = (string)($_POST['kind'] ?? 'material');
        $unit        = trim((string)($_POST['unit'] ?? 'g'));
        $purchaseQty = ($_POST['purchase_qty'] ?? '') === '' ? null : (float)$_POST['purchase_qty'];
        $supplierId  = (int)($_POST['supplier_id'] ?? 0) ?: null;
        $isSupplied  = isset($_POST['is_supplied']) ? 1 : 0;
        $isStock     = isset($_POST['is_stock_managed']) ? 1 : 0;
        $hasExpiry   = isset($_POST['has_expiry']) ? 1 : 0;

        $v = (new Validator())
            ->required($data['name'], '材料名')
            ->maxLength($data['name'], 100, '材料名')
            ->required($unit, '単位')
            ->maxLength($unit, 20, '単位');
        if ($v->fails() || !isset(self::KIND_LABELS[$kind])) {
            Session::flash('warn', implode(' ', [...$v->errors(), ...(isset(self::KIND_LABELS[$kind]) ? [] : ['種類を選んでください。'])]));
            App::redirect($id > 0 ? '/materials/edit?id=' . $id : '/materials/edit');
        }

        $params = [
            $data['name'], $data['alias_names'], $kind, $data['category'], $data['maker_name'], $data['allergens'],
            $unit, $data['purchase_unit'], $purchaseQty,
            $supplierId, $isSupplied, $isStock, $hasExpiry, $data['note'],
        ];

        if ($id > 0) {
            Db::exec(
                'UPDATE materials SET name=?, alias_names=?, kind=?, category=?, maker_name=?, allergens=?,
                        unit=?, purchase_unit=?, purchase_qty=?,
                        supplier_id=?, is_supplied=?, is_stock_managed=?, has_expiry=?, note=?, updated_by=?
                  WHERE id = ?',
                [...$params, Auth::id(), $id]
            );
            OperationLog::write('update', 'materials', (string)$id, '材料を修正しました：' . $data['name']);
            Session::flash('info', '材料を修正しました。');
        } else {
            $id = Db::insert(
                'INSERT INTO materials (name, alias_names, kind, category, maker_name, allergens,
                        unit, purchase_unit, purchase_qty,
                        supplier_id, is_supplied, is_stock_managed, has_expiry, note, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [...$params, Auth::id()]
            );
            OperationLog::write('create', 'materials', (string)$id, '材料を登録しました：' . $data['name']);
            Session::flash('info', '材料を登録しました。');
        }

        App::redirect('/materials?q=' . urlencode((string)$data['name']));
    }

    /** 入力欄の選択肢用（材料の一覧） */
    public static function options(): array
    {
        return Db::all('SELECT id, name, unit FROM materials WHERE deleted_at IS NULL ORDER BY name');
    }
}
