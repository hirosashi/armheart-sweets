#!/bin/bash
# さくら環境（jyunbi）へ反映する。
# 使い方: ./deploy.sh
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)/src"
HOST="jyunbi.sakura.ne.jp"
USER="jyunbi"
DEST="/home/jyunbi/www/armheart.com"

: "${SFTP_PASS:?SFTP_PASS を環境変数で渡してください}"

cd "$SRC"
tar czf /tmp/deploy.tgz \
  --exclude='config/config.local.php' \
  --exclude='storage/logs/*' \
  --exclude='install.php' \
  .

sshpass -p "$SFTP_PASS" scp -o StrictHostKeyChecking=no /tmp/deploy.tgz "$USER@$HOST:/tmp/deploy.tgz"
sshpass -p "$SFTP_PASS" ssh -o StrictHostKeyChecking=no "$USER@$HOST" \
  "/bin/sh -c 'mkdir -p $DEST/storage/logs && cd $DEST && tar xzf /tmp/deploy.tgz && rm -f /tmp/deploy.tgz && chmod -R 755 $DEST && chmod -R 777 $DEST/storage/logs && ls -la $DEST'"
echo "反映が完了しました: https://jyunbi.sakura.ne.jp/armheart.com/"
