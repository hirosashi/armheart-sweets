---
name: add-screen
description: 新しい画面（Controller + View + ルート + 権限 + ヘッダーメニュー + 業務工程）をこのプロジェクトの規約に沿って追加する。「〇〇画面を追加」「〇〇の登録機能を作る」時に使う。
---

# 新しい画面の追加テンプレート

## 構成（すべて `src/` 配下）
| 役割 | 場所 |
|---|---|
| ルート | `index.php`（`$router->get/post('/path', [XController::class, 'method'])`） |
| Controller | `app/Controllers/XController.php`（`namespace App\Controllers;` 静的メソッド） |
| View | `app/Views/x/index.php` 等。`View::render('x/index', [...])` |
| 権限 | `app/Core/Auth.php` の `EDIT_PERMISSIONS`（`'x' => ['admin', 'purchase']` 等。roles: admin / purchase / production / viewer） |
| メニュー | `app/Views/layout.php` の `$menuWork`（運用系・常時表示）または `$menuSetup`（登録・確認プルダウン） |
| 業務工程 | `app/Services/Flow.php` の `setupSteps()` / `weeklySteps()`。`label / path / state / detail` を返す（path は `#anchor` や `?status=` 可） |
| DB | `db/schema.sql`（`apply-db-schema` スキル） |

## Controller の型
```php
public static function index(): void
{
    Auth::requireLogin();
    // 取得は Db::rows / Db::row / Db::value（プリペアド）
    View::render('x/index', ['items' => $items, 'canEdit' => Auth::can('x')]);
}

public static function save(): void
{
    Auth::requireLogin();
    Csrf::verify();
    if (!Auth::can('x')) { Session::flash('warn', 'この操作をする権限がありません。'); App::redirect('/x'); }
    $v = new Validator($_POST);
    $v->required('name', '名前')->maxLength('name', 100, '名前');   // メソッドは required/maxLength/number/positive/fails/errors のみ
    if ($v->fails()) { Session::flash('warn', implode(' ', $v->errors())); App::redirect('/x/edit?id=' . $id); }
    $pdo = Db::conn(); $pdo->beginTransaction();
    try { Db::exec('INSERT ...', [...]); $pdo->commit(); } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    OperationLog::write('update', 'x', (string)$id, '〇〇を登録しました');
    Session::flash('info', '登録しました。');
    App::redirect('/x');
}
```

## View の型
- 先頭で `use App\Core\{App,Auth,Csrf,View};`
- 出力は必ず `View::e()`、日時は `View::dt()/View::d()`、数値は `View::num($v, 小数桁)`
- フォームは `<form method="post" action="<?= View::e(App::url('/x/save')) ?>"><?= Csrf::field() ?>`
- 件数の多い `<select>` には `class="select-search"` を付ける（`assets/js/app.js` が検索付きにする）
- 見出し `h1.page-title` / 説明 `p.page-lead` / 表 `table.table` / ボタン `btn btn-primary|btn-ghost` / 注記 `.note` / 不足強調 `.judge-short`
- ページ内アンカーは `<h2 id="short" class="sec-title">` のように id を付け、Flow の path から参照

## 文言ルール
- 現場向けの平易な日本語。「直す」「やめる」「できあがり」「足りない分」など既存表現に合わせる。専門語（マスタ・トランザクション等）は画面に出さない。
- 日時はすべて `Clock` 経由（`date()`/`NOW()` 禁止）。

## 仕上げ
1. `verify-changes` → 2. ローカルで画面確認（`run-local-server`）→ 3. `docs/開発方針・環境設計.md` に画面を追記 → 4. `deploy-dev-server`
