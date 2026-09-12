---
name: build-seed-from-excel
description: エンドユーザー提供のExcel（配合表・原価計算表・発注書）から db/seed_real.sql を生成し、材料・部位・配合・商品・業者を投入する。新商品や配合改訂の資料を受け取ったときに使う。
---

# Excel資料からのマスタ投入

## 入力
`tools/source/` に置く4ファイル（ファイル名は `tools/build_seed.py` の `RECIPE / COST_AH / COST_IC / ORDER` 定数と一致させる）:
- 【改訂】最終配合表（…）バッチ配合量追加.xlsx … 部位・バッチ配合・充填量・取り数・歩留まり
- 00.原価計算表アルムハート.xlsx / 00.原価計算表伊藤忠_….xlsx … 原材料名・メーカー・kg単価・単価根拠・アレルゲン
- お餅シート発注書.xlsx … 業者・発注書レイアウト

新しい資料は添付DLパスからコピー: `cp ~/attachments/<id>/*.xlsx tools/source/`

## 手順
1. まず openpyxl でシート構成を確認し、列位置が前回と同じかを見る（`python3 -c "import openpyxl;wb=openpyxl.load_workbook(p,data_only=True);print(wb.sheetnames)"`）。ずれていれば `build_seed.py` の列参照を修正。
2. 生成: `cd /home/ubuntu/phase1_dev && python3 tools/build_seed.py` → `db/seed_real.sql`
3. 生成物を目で確認（件数・重複名・`NO_STOCK`（水）が在庫対象外になっているか・メーカー名の「販売者：」接頭辞除去）。
4. ローカル投入: `sudo mysql sweets_dev < db/seed_real.sql`（既存データがある場合は INSERT IGNORE / ON DUPLICATE の方針を確認し、必要なら差分だけ抜き出す）。
5. 開発サーバ投入は `apply-db-schema` と同じ「SQLを転送→PHPで実行」方式。
6. 画面で確認: `/materials`（件数）、`/parts`（バッチ合計量・配合）、`/products/show?id=`（充填量・取り数・使う個数）、`/require`（仕込み回数が妥当か）。

## 既知の論点（資料側の未確定事項。投入前に確認を促す）
- トッピング／滑り止めの1バッチ量（4g／3g は1台量の可能性）
- 歩留まり 0.9・バッチ切り上げの正式承認
- φ7cm茶スポンジ「56個取り」の意味
- 計算式: 必要バッチ数 = 台数 × (充填量 ÷ 取り数 × 使う個数) ÷ 歩留まり ÷ バッチ合計量
