---
name: project-conventions
description: スイーツ生産管理システム（アルムハート／phase1_dev）の開発規約と環境情報。このプロジェクトでコードを書く・反映する・報告する前に必ず読む。
---

# プロジェクト規約（スイーツ生産管理システム フェーズA）

## 環境
- ローカル: `/home/ubuntu/phase1_dev`（Gitリポジトリではない。PRではなく開発サーバ反映で納品）
- ローカルDB: MySQL `sweets_dev` @127.0.0.1（root は `sudo mysql`）。設定 `src/config/config.local.php`
- 開発サーバ: `https://jyunbi.sakura.ne.jp/armheart.com/`（`sakura.jp` は解決不可）。PHP 8 / Apache mod_rewrite / MySQL 8 / HTTPS
- ログイン: `admin` / `Armheart2026`（初期値。ユーザー了承済み）
- 秘密情報（SFTP/DBパスワード、`config.sakura.php` の中身）は会話・ログ・ソース・ドキュメントに出さない

## 技術
- 素のPHP + PDO。**Composer・外部ライブラリ禁止**（JSも自前 `assets/js/app.js`）
- サーバ側HTML生成・通常のページ遷移。PC/タブレット横向き前提
- 文字コード utf8mb4。`.htaccess` による `app/` `config/` 保護を維持

## 時刻（最重要）
- すべて `App\Core\Clock` 経由。`date()` `time()` `strtotime()` `new DateTime()` `NOW()` `CURDATE()` を業務コードで直接書かない
- PHP `Asia/Tokyo`（`Clock::init()`）、MySQL 接続直後に `SET time_zone='+09:00'`
- 保存 `DATETIME` の `Y-m-d H:i:s`（`TIMESTAMP` 禁止）、表示 `Y/m/d H:i`（`View::dt()`）、日付 `View::d()`
- 週は月曜始まり `Clock::weekStart()`

## セキュリティ／権限
- GET は `Auth::requireLogin()`、POST は `Csrf::verify()` → `Auth::can('<area>')` → `Validator` → `OperationLog::write()`
- roles: admin / purchase（発注担当）/ production（製造担当）/ viewer。権限表は `Auth::EDIT_PERMISSIONS`
- 出力は `View::e()` 必須。SQLはプリペアドのみ

## 業務ロジック
- 配合はバッチ単位。1台あたり実使用量 = 充填量 ÷ 取り数 × 使う個数
- 必要バッチ数 = 台数 × 実使用量 ÷ 歩留まり(0.9) ÷ バッチ合計量（既定で切り上げ）
- 材料必要量 = 必要バッチ数 × バッチ配合量。水は必要量に含めるが在庫・発注対象外（`is_stock_managed=0`）
- 部位「できあがり」で `Consumption::apply`（できた回数 × 配合量を賞味期限順に在庫から引く）、戻すと `revert`
- 業務工程12手順は `Services/Flow.php`。状態は 済／途中／未実施

## UI／文言
- ヘッダー1行（タイトル=ホームリンク、運用メニュー、登録・確認プルダウン、右端ユーザー/ログアウト）固定。左250pxは「業務工程」固定
- 現場向けの平易な日本語（「直す」「やめる」「足りない分」「できあがり」）。専門用語・英語を画面に出さない
- 件数の多い select は `class="select-search"`
- `.table-narrow` に max-width を付けない（ユーザー指示）

## 進め方・報告
- 変更後は `verify-changes` → `run-local-server` で目視 → `deploy-dev-server` → `cleanup-test-data`
- 報告は簡潔に: URL・変更点の箇条書き・確認済み内容・現場に確認したい点（あれば1つ）。スクリーンショットを添付
- 仮決めした仕様（歩留まり等）は「仮」と明示し、画面を見て直せる作りにする
- 設計書 `docs/開発方針・環境設計.md` を機能追加時に更新
