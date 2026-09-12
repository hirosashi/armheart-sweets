---
name: apply-db-schema
description: テーブル追加・列変更を db/schema.sql に追記し、ローカル sweets_dev と開発サーバDBの両方へ適用して存在確認する。「テーブル追加」「カラム追加」「スキーマ変更」の際に使う。
---

# DBスキーマ変更の適用

## 方針
- 正本は `db/schema.sql`。新テーブルは既存定義と同じ書式で追記する
  （`ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`、日時は `DATETIME`、`TIMESTAMP` 禁止、COMMENT は日本語）。
- 適用は「ローカル → 開発サーバ」の順。既存データを壊す `DROP` は使わない（ALTER/CREATE のみ）。

## 手順
1. `db/schema.sql` に CREATE TABLE / ALTER 文を追記。差分SQLを `/tmp/change.sql` にも書き出す。
2. ローカル適用（root は sudo 経由。`mysql -uroot` は 1698 で失敗する）:
   ```bash
   sudo mysql sweets_dev < /tmp/change.sql
   sudo mysql sweets_dev -e "SHOW CREATE TABLE <table>\G"
   ```
3. 開発サーバ適用。長い `ssh ... php -r "..."` はクォートで失敗するので、**スクリプトファイルを転送して実行**する:
   - `/tmp/mk_table.php` を作成（`config/config.php` から接続、`/tmp/change.sql` を実行、`SHOW TABLES LIKE` で確認、秘密値は出力しない）:
     ```php
     <?php
     $c = require __DIR__ . '/config/config.php'; $d = $c['db'];
     $p = new PDO("mysql:host={$d['host']};dbname={$d['name']};charset=utf8mb4", $d['user'], $d['pass'],
                  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
     $p->exec("SET time_zone = '+09:00'");
     if ($p->query("SHOW TABLES LIKE '<table>'")->rowCount() === 0) { $p->exec(file_get_contents('/tmp/change.sql')); }
     echo $p->query("SHOW TABLES LIKE '<table>'")->rowCount(), PHP_EOL;
     ```
     （ALTER の場合は `SHOW COLUMNS FROM <table> LIKE '<col>'` で存在判定）
   - 転送・実行（SFTP_PASS は `deploy-dev-server` と同じ方法で読み込む）:
     ```bash
     D=/home/jyunbi/www/armheart.com; H=jyunbi@jyunbi.sakura.ne.jp
     sshpass -p "$SFTP_PASS" scp -o StrictHostKeyChecking=no /tmp/change.sql /tmp/mk_table.php $H:/tmp/
     sshpass -p "$SFTP_PASS" ssh -o StrictHostKeyChecking=no $H "cp /tmp/mk_table.php $D/mk_table.php && cd $D && php mk_table.php; rm -f $D/mk_table.php /tmp/mk_table.php /tmp/change.sql"
     ```
   - 出力 `1` で成功。実行後に `mk_table.php` を必ず削除する（公開領域に残さない）。
4. 関連するPHPコードを反映（`deploy-dev-server`）し、該当画面が 200 で開くことを確認。
5. `docs/開発方針・環境設計.md` にテーブルの目的を1行追記する。
