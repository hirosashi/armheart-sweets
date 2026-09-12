---
name: run-local-server
description: ローカルでPHP内蔵サーバを起動し、admin でログインして画面確認できる状態にする。セッション再開後・画面変更の目視確認時に使う。
---

# ローカル動作確認サーバの起動

## 手順
1. MySQL が動いているか確認し、必要なら起動:
   ```bash
   sudo systemctl start mysql 2>/dev/null || sudo service mysql start
   sudo mysql sweets_dev -e "SELECT COUNT(*) materials FROM materials"
   ```
2. サーバ起動（バックグラウンド、ログは storage/logs へ）:
   ```bash
   cd /home/ubuntu/phase1_dev && (php -S 127.0.0.1:8088 -t src dev_router.php > /tmp/phpsrv.log 2>&1 &) ; sleep 1
   curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8088/login   # 200 を期待
   ```
   - `000` の場合はポート使用中 → `pkill -f 'php -S 127.0.0.1:8088'` して再実行。
   - 設定は `src/config/config.local.php`（DB `sweets_dev` @127.0.0.1）。
3. ブラウザ（`computer` ツール）で `http://127.0.0.1:8088/login` を開き、`admin` / `Armheart2026` でログイン。
   - パスワードが変わっていれば `reset-admin-password` スキルでローカルDBを戻す:
     `cd /home/ubuntu/phase1_dev && mkdir -p src/tools && cp tools/reset_admin_pw.php src/tools/ && php src/tools/reset_admin_pw.php admin Armheart2026 && rm -rf src/tools`
4. 主要画面: `/`, `/require`, `/orders`, `/progress`, `/stock`, `/products`, `/parts`, `/materials`
5. 確認中に作ったテストデータは `cleanup-test-data` スキルで片付ける。

## 補足
- `dev_router.php` は実在ファイル（css/js/画像）をそのまま返し、それ以外を `src/index.php` に渡す。本番は Apache + mod_rewrite。
- PHPエラーは `src/storage/logs/php_error.log` と `/tmp/phpsrv.log` を見る。
