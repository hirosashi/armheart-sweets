---
name: deploy-prod-server
description: 本番（公開）サーバ（さくら smileyou-plus.sakura.ne.jp → https://armheart.com/system/）へ src/ を反映し、必要ならDB変更を適用して確認する。「本番へ反映」「公開サイトへコピー」と言われたら使う。
---

# 本番サーバへの反映

## 前提
- 公開URL: `https://armheart.com/system/`（`base_path` は `/system`）
- SSH: host `smileyou-plus.sakura.ne.jp` / 配置先 `/home/smileyou-plus/www/system`
- secret（org スコープ。exec の `env` に `secret:session:NAME` で束縛。値は出力しない）
  - `ARMHEART_PROD_SSH_USER` / `ARMHEART_PROD_SSH_PASS`
  - `ARMHEART_PROD_DB_PASS`（DB: host `mysql2105.db.sakura.ne.jp` / DB名・ユーザー `smileyou-plus_sys` / MySQL 8.0）
- サーバのログインシェルは csh。複数コマンドは `ssh ... /bin/sh <<'EOF' ... EOF` で sh に渡す（`2>&1` 等は csh だとエラー）。
- 本番は PHP 8.3。設定は `config/config.production.php`（サーバにだけ置く。git管理外、`deploy.sh` は転送しない）。このファイルがあると `config.php` は必ずこれを読む。`debug` は false。
- さくらの「国外IPアドレスフィルタ」がONだと、海外IPのDevinからは SSH が `Permission denied`、FTPは切断になる（許可IPリストはウェブにしか効かない）。拒否されたらまずフィルタ状態をユーザーに確認する。

## 手順
1. ローカル検査（`verify-changes`）を通す。
2. DB変更がある場合は先にバックアップしてから適用:
   ```bash
   # env: {"P":"secret:session:ARMHEART_PROD_SSH_PASS","U":"secret:session:ARMHEART_PROD_SSH_USER","DBP":"secret:session:ARMHEART_PROD_DB_PASS"}
   export SSHPASS="$P"; O="-o StrictHostKeyChecking=no -o PreferredAuthentications=password -o PubkeyAuthentication=no"
   { printf 'umask 077; printf "[client]\\nhost=mysql2105.db.sakura.ne.jp\\nuser=smileyou-plus_sys\\npassword=\\"%%s\\"\\ndefault-character-set=utf8mb4\\n" %s > ~/.my_armheart.cnf\n' "'$DBP'"
     echo 'mkdir -p ~/backup && mysqldump --defaults-extra-file=$HOME/.my_armheart.cnf --no-tablespaces --set-gtid-purged=OFF smileyou-plus_sys > ~/backup/prod_$(date +%Y%m%d%H%M).sql && ls -la ~/backup'
   } | sshpass -e ssh $O "$U@smileyou-plus.sakura.ne.jp" /bin/sh
   ```
   migration SQL を `scp` で `~/backup/` に送り `mysql --defaults-extra-file=$HOME/.my_armheart.cnf smileyou-plus_sys < ~/backup/xxx.sql` で適用。終わったら `~/.my_armheart.cnf` は削除する。
3. コード反映:
   ```bash
   # env: {"PROD_SSH_USER":"secret:session:ARMHEART_PROD_SSH_USER","PROD_SSH_PASS":"secret:session:ARMHEART_PROD_SSH_PASS"}
   cd /home/ubuntu/phase1_dev && ./deploy.sh prod
   ```
4. 確認: 未ログイン `/` → 302、`/login` → 200、`/config/config.production.php`・`/db/`・`/storage/logs/` → 403。ログイン後 `/ /schedule /materials /parts /products /require /progress /orders /stock` が 200 で warning/fatal なし（ログイン手順は `deploy-dev-server` と同じ。admin のパスワードはユーザー提供の本番用の値）。

## 本番データの扱い
- 初回移植時はマスタ（users・companies・suppliers・materials・parts・part_materials・products・product_parts・product_materials）だけを開発DBからコピーし、在庫・発注・進捗・案件・ログは空で開始した。
- 以後、本番は実運用データ。開発DBから上書きしない。テストデータを本番で作らない。
