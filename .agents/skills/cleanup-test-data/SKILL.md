---
name: cleanup-test-data
description: ローカル動作確認で作った進捗・在庫調整・自動引き当て・テスト材料などを削除し、在庫数量を元に戻す。動作確認の後に必ず使う。
---

# テストデータの後片付け（ローカル sweets_dev）

原則: **開発サーバのデータは触らない**（ユーザーが触った結果として残す。必要なときは棚卸しで直すよう案内）。ローカルのみ以下で戻す。

## 1. 作業前に控えを取る（確認開始時）
```bash
sudo mysql sweets_dev -e "SELECT material_id, qty FROM inventories ORDER BY id" > /tmp/inv_before.txt
sudo mysql sweets_dev -e "SELECT MAX(id) FROM inventory_adjustments; SELECT MAX(id) FROM part_progress; SELECT MAX(id) FROM materials; SELECT MAX(id) FROM orders;" > /tmp/ids_before.txt
```

## 2. 片付け
- 部位進捗の自動引き当てを戻す: 画面で対象部位を「これから」に戻して保存（`Consumption::revert` が在庫を復元）→ その後
  ```sql
  DELETE FROM part_progress WHERE target_week = '<週の月曜>' AND part_id = <id>;
  DELETE FROM part_consumptions WHERE target_week = '<週の月曜>';
  ```
- テストで追加した在庫調整: `DELETE FROM inventory_adjustments WHERE id > <控えのMAX>;`（在庫数量は `inventories.qty` を控えの値に UPDATE）
- テスト材料: `DELETE FROM materials WHERE id > <控えのMAX> AND name LIKE 'テスト%';`（配合に使っていれば `part_materials`/`product_materials` を先に削除）
- テスト発注: `DELETE FROM order_items WHERE order_id IN (...); DELETE FROM orders WHERE id > <控えのMAX>;`
- 操作ログ（`operation_logs`）は消さなくてよい。

## 3. 復元確認
```bash
sudo mysql sweets_dev -e "SELECT material_id, qty FROM inventories ORDER BY id" | diff - /tmp/inv_before.txt && echo "在庫 復元OK"
sudo mysql sweets_dev -e "SELECT COUNT(*) FROM part_consumptions"
```

## 4. ブラウザで `/stock` `/progress` を再表示し、表示上も元に戻っていることを確認。
