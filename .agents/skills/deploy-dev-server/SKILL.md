---
name: deploy-dev-server
description: 開発サーバ（さくら jyunbi.sakura.ne.jp/armheart.com）へ src/ を反映し、HTTP疎通を確認する。「開発サーバへ反映」「デプロイ」「さくらに上げる」と言われたら使う。
---

# 開発サーバへの反映

## 前提
- プロジェクト: `/home/ubuntu/phase1_dev`
- 反映スクリプト: `deploy.sh`（`src/` を tar→scp→ssh で展開。`config.local.php`・`install.php`・ログは除外）
- SFTP: host `jyunbi.sakura.ne.jp` / port 22 / user `jyunbi` / 配置先 `/home/jyunbi/www/armheart.com`
- パスワードは組織 secret **`ARMHEART_SFTP_PASS`**（exec の `env` に `secret:session:ARMHEART_SFTP_PASS` で束縛）。添付「開発環境.txt」の値は古く認証に失敗する。値を会話・ログ・ソースに出力しないこと。
- 確認URLは `https://jyunbi.sakura.ne.jp/armheart.com/`（`sakura.jp` は名前解決不可）

## 手順
1. 反映前にローカル検査（`verify-changes` スキル）を通す。
2. パスワードを環境変数へ読み込み、デプロイ実行（1コマンドで）:
   ```bash
   # exec の env に {"SFTP_PASS": "secret:session:ARMHEART_SFTP_PASS"} を指定して実行
   cd /home/ubuntu/phase1_dev && ./deploy.sh
   ```
   - `Permission denied` が出たら secret が未登録／変更されている。`request_secret`（ARMHEART_SFTP_PASS, org スコープ）で再登録を依頼する。
3. DBスキーマ変更を伴う場合は `apply-db-schema` スキルで開発サーバDBにも適用する。
4. 疎通確認（未ログインは 302、静的ファイルは 200）:
   ```bash
   for p in "" login assets/css/app.css assets/js/app.js; do
     printf '%s ' "$p"; curl -s -o /dev/null -w '%{http_code}\n' "https://jyunbi.sakura.ne.jp/armheart.com/$p"
   done
   ```
5. ログイン後画面の確認が必要なら cookie jar でログインして対象URLを叩く:
   ```bash
   J=/tmp/cj.txt; U=https://jyunbi.sakura.ne.jp/armheart.com
   T=$(curl -s -c $J $U/login | grep -oP 'name="_token" value="\K[^"]+')
   curl -s -b $J -c $J -o /dev/null -d "_token=$T&login_id=admin&password=Armheart2026" $U/login
   for p in progress stock require orders; do printf '%s ' $p; curl -s -b $J -o /dev/null -w '%{http_code}\n' $U/$p; done
   ```
   （`_token` の input 名は `src/app/Core/Csrf.php` を確認）
6. ユーザーへは URL と変更点を簡潔に報告する。開発サーバの在庫等はデモ値である旨を必要に応じて添える。

## 注意
- `.htaccess`（`src/.htaccess`, `src/app/.htaccess`）は削除・除外しない（内部フォルダ保護）。
- `config/config.sakura.php` は開発サーバ側に既にあるもの。上書きしてよいが、内容を会話に出さない。
