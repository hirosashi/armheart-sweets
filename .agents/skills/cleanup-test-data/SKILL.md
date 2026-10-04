---
name: cleanup-test-data
description: ローカル動作確認で作った発注（jobs）・進捗・在庫調整・テスト商品・材料発注を削除し、在庫数量を元に戻す。動作確認の後に必ず使う。
---

# ローカルのテストデータを片付ける（sweets_dev）

## 始める前に控えを取る
```bash
sudo mysql sweets_dev -e "SELECT material_id, qty FROM inventory ORDER BY id" > /home/ubuntu/inv_before.txt
sudo mysql sweets_dev -e "SELECT MAX(id) FROM inventory_adjustments; SELECT MAX(id) FROM purchase_orders; SELECT MAX(id) FROM products; SELECT COUNT(*) FROM jobs;" > /home/ubuntu/ids_before.txt
```
控えは2つのファイルに分ける。在庫の控えに件数などを追記すると、あとの `diff` で差分があるように見えてしまう。

## テーブル名（間違えやすいもの）
- 在庫は `inventory`（`inventories` ではない）。在庫を直した記録は `inventory_adjustments`（`inventory_id`, `before_qty`, `after_qty`, `note`）。
- 材料の発注は `purchase_orders` / `purchase_order_items.order_id`。`purchase_orders` に `po_no` 列はない。
- 発注（つくる予定）は `jobs` → `job_items`（商品ごと）→ `job_parts`（`job_item_id`）。jobs を消すと cascade で一緒に消える。
- 部位の1バッチ量は `parts.batch_total_qty`。

## 進み具合を戻す（画面で操作する）
- /progress?date= で、状態を「これから」にして保存する。できた回数は自動で0になり、在庫も戻る。

## 発注を消す（画面で操作する）
- /schedule?edit=ID で「この発注を削除する」を押し、確認ダイアログで OK を押す。
- 紐づいた材料発注は削除されず、`purchase_orders.job_id = NULL` で残る。控えの MAX(id) より大きい行を手で消す。
  `DELETE FROM purchase_order_items WHERE order_id > <MAX>; DELETE FROM purchase_orders WHERE id > <MAX>;`

## SQL で片付ける
```sql
DELETE FROM part_progress;          -- 開始前に0件だった場合だけ全件消す
DELETE FROM part_consumptions;
DELETE FROM inventory_adjustments WHERE id > <控えのMAX>;  -- 控えが NULL なら全件
DELETE FROM product_parts WHERE product_id = <テスト商品ID>;
DELETE FROM products WHERE id = <テスト商品ID> AND name LIKE 'テスト%';
```

## 元に戻ったか確かめる
```bash
sudo mysql sweets_dev -e "SELECT material_id, qty FROM inventory ORDER BY id" | diff /home/ubuntu/inv_before.txt - && echo OK
sudo mysql sweets_dev -e "SELECT (SELECT COUNT(*) FROM jobs),(SELECT COUNT(*) FROM job_items),(SELECT COUNT(*) FROM job_parts),(SELECT COUNT(*) FROM part_progress)"
```
