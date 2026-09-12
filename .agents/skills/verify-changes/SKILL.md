---
name: verify-changes
description: コード変更後の検査一式（PHP構文チェック・Clockテスト・主要URLのステータス確認・禁止関数の検出）。反映前に必ず実行する。
---

# 変更後の検査一式

このプロジェクトは Composer / lint ツールを使わないため、以下を検査コマンドとする。

## 手順
1. 構文チェック（全PHP）:
   ```bash
   cd /home/ubuntu/phase1_dev && find src tests tools -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' ; echo "lint done"
   ```
   何も出力されなければOK。
2. 時刻テスト（DB接続あり・21項目）:
   ```bash
   cd /home/ubuntu/phase1_dev && php tests/clock_test.php
   ```
3. 禁止APIの検出（`Clock` 以外で直接日時を扱っていないか）:
   ```bash
   cd /home/ubuntu/phase1_dev && grep -rnE "\b(date|time|strtotime|mktime)\(|new DateTime|NOW\(\)|CURDATE\(\)" src/app --include=*.php | grep -v Core/Clock.php
   ```
   ヒットしたら `Clock::now()/today()/weekStart()/dt()/d()` 等へ置き換える。
   （既知の例外: `Core/Db.php` のコメント行、`HomeController` の `SELECT NOW()`（DB時計の表示用）、`schema.sql` の `DEFAULT CURRENT_TIMESTAMP`、`tools/` の単発スクリプト）
4. セキュリティ規約の目視確認（変更した Controller について）:
   - GET: `Auth::requireLogin()` があるか
   - POST: `Csrf::verify()` → `Auth::can('<area>')` → 入力検証 → `OperationLog::write()` の順か
   - View の出力は `View::e()` で必ずエスケープしているか
5. 主要URLのステータス（`run-local-server` 起動後）:
   ```bash
   for p in login products parts progress require orders stock materials; do
     printf '%-10s ' $p; curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8088/$p
   done
   ```
   未ログインは `/login` が 200、他は 302 で正常。500 が出たら `src/storage/logs/php_error.log` を確認。
6. 画面変更がある場合はブラウザで実際に開いてスクリーンショットを取り、報告に添付する。
