#!/bin/bash
# さくら環境へ src/ を反映する。
# 使い方:
#   SFTP_PASS=... ./deploy.sh            開発サーバ（jyunbi.sakura.ne.jp/armheart.com）
#   PROD_SSH_USER=... PROD_SSH_PASS=... ./deploy.sh prod
#                                        本番（armheart.com/system）
# 環境ごとの設定ファイル（config.local/sakura/production.php）は転送しない。
# 各サーバに置いてあるものがそのまま使われる。
set -euo pipefail

TARGET="${1:-dev}"
SRC="$(cd "$(dirname "$0")" && pwd)/src"

case "$TARGET" in
  dev)
    HOST="jyunbi.sakura.ne.jp"
    USER="jyunbi"
    DEST="/home/jyunbi/www/armheart.com"
    URL="https://jyunbi.sakura.ne.jp/armheart.com/"
    : "${SFTP_PASS:?SFTP_PASS を環境変数で渡してください}"
    PASS="$SFTP_PASS"
    KEEP_CONFIG="config/config.sakura.php"
    AFTER=""
    ;;
  prod)
    HOST="smileyou-plus.sakura.ne.jp"
    : "${PROD_SSH_USER:?PROD_SSH_USER を環境変数で渡してください}"
    : "${PROD_SSH_PASS:?PROD_SSH_PASS を環境変数で渡してください}"
    USER="$PROD_SSH_USER"
    DEST="/home/smileyou-plus/www/system"
    URL="https://armheart.com/system/"
    PASS="$PROD_SSH_PASS"
    KEEP_CONFIG="config/config.production.php"
    # ベーシック認証。設定はサーバのホーム直下（Git管理外）にあり、反映で上書きされた .htaccess に付け直す
    AFTER=" && { test ! -f /home/smileyou-plus/system_basic_auth.htaccess || cat /home/smileyou-plus/system_basic_auth.htaccess >> $DEST/.htaccess; }"
    ;;
  *)
    echo "使い方: ./deploy.sh [dev|prod]" >&2
    exit 1
    ;;
esac

cd "$SRC"
tar czf /tmp/deploy.tgz \
  --exclude='config/config.local.php' \
  --exclude='config/config.sakura.php' \
  --exclude='config/config.production.php' \
  --exclude='storage/logs/*' \
  --exclude='install.php' \
  .

export SSHPASS="$PASS"
SSH_OPTS="-o StrictHostKeyChecking=no -o PreferredAuthentications=password -o PubkeyAuthentication=no"
sshpass -e scp $SSH_OPTS /tmp/deploy.tgz "$USER@$HOST:/tmp/deploy.tgz"
sshpass -e ssh $SSH_OPTS "$USER@$HOST" \
  "/bin/sh -c 'test -f $DEST/$KEEP_CONFIG || { echo \"$DEST/$KEEP_CONFIG がありません\" >&2; exit 1; }; mkdir -p $DEST/storage/logs && cd $DEST && tar xzf /tmp/deploy.tgz${AFTER} && rm -f /tmp/deploy.tgz && chmod -R 755 $DEST && chmod 600 $DEST/$KEEP_CONFIG && chmod -R 777 $DEST/storage/logs && ls -la $DEST'"
rm -f /tmp/deploy.tgz
echo "反映が完了しました: $URL"
