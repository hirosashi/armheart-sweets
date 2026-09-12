---
name: reset-admin-password
description: 開発サーバまたはローカルの admin パスワードを Armheart2026 に戻し、ログイン失敗ロックと初回変更強制を解除する。「ログインできない」「パスワードを戻して」と言われたら使う。
---

# 管理者パスワードのリセット／ロック解除

`tools/reset_admin_pw.php <login_id> <password>` が、`password_hash` 更新・`must_change_pw=0`・`is_active=1`・`login_attempts` 削除を行う。
既定の初期パスワードは `Armheart2026`（ユーザー了承済みの値。変えない）。

## 開発サーバ
```bash
D=/home/jyunbi/www/armheart.com; H=jyunbi@jyunbi.sakura.ne.jp
# SFTP_PASS は deploy-dev-server スキルの方法で読み込む
sshpass -p "$SFTP_PASS" ssh -o StrictHostKeyChecking=no $H "mkdir -p $D/tools"
sshpass -p "$SFTP_PASS" scp -o StrictHostKeyChecking=no /home/ubuntu/phase1_dev/tools/reset_admin_pw.php $H:$D/tools/
sshpass -p "$SFTP_PASS" ssh -o StrictHostKeyChecking=no $H "cd $D && php tools/reset_admin_pw.php admin Armheart2026; rm -rf $D/tools"
```
スクリプトは `dirname(__DIR__).'/config/config.php'` を読むため、**アプリ直下の `tools/` に置く**（`$D/tools/reset_admin_pw.php` → `$D/config/config.php`）。実行後は必ず削除（公開領域に残さない）。

## ローカル
```bash
cd /home/ubuntu/phase1_dev && mkdir -p src/tools && cp tools/reset_admin_pw.php src/tools/ && php src/tools/reset_admin_pw.php admin Armheart2026 && rm -rf src/tools
```

## 確認・報告
- `curl` でログインPOSTが 302（ホームへ）になること。
- ユーザーへは「`admin` / `Armheart2026` でログイン可。ロック解除・パスワード変更の強制OFF」を1〜2行で報告。
- パスワードのハッシュ・DB接続情報は出力しない。
