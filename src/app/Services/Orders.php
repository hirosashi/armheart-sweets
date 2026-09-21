<?php
namespace App\Services;

use App\Core\Db;

/**
 * 発注の達成度。発注は1日で終わらない（一部納品がある）ため、
 * 「済／未」ではなく品目ごとの納品数から進み具合を出す。
 */
class Orders
{
    /**
     * 品目の納品状況から発注全体の状態を決める。
     *   全品目が発注数以上に納品 → delivered、1つでも納品あり → partial、なし → ordered
     */
    public static function statusFromItems(int $orderId): string
    {
        $r = Db::one(
            'SELECT COUNT(*) AS cnt,
                    SUM(received_qty >= qty) AS done_cnt,
                    SUM(received_qty > 0)   AS started_cnt
               FROM purchase_order_items WHERE order_id = ?',
            [$orderId]
        );
        $cnt = (int)($r['cnt'] ?? 0);
        if ($cnt === 0) {
            return 'ordered';
        }
        if ((int)$r['done_cnt'] === $cnt) {
            return 'delivered';
        }
        return (int)$r['started_cnt'] > 0 ? 'partial' : 'ordered';
    }

    /**
     * 未完了の発注（未発注・発注済・一部納品）と品目ベースの達成度。
     * @return array<int, array{id:int, order_no:string, supplier_name:string, status:string, order_date:?string,
     *                          desired_date:?string, period_from:?string, period_to:?string,
     *                          item_count:int, received_count:int, rate:int}>
     */
    public static function open(int $limit = 20): array
    {
        $rows = Db::all(
            "SELECT o.id, o.order_no, o.status, o.order_date, o.desired_date, o.period_from, o.period_to,
                    s.name AS supplier_name,
                    COUNT(i.id) AS item_count,
                    IFNULL(SUM(i.received_qty >= i.qty), 0) AS received_count
               FROM purchase_orders o
               JOIN suppliers s ON s.id = o.supplier_id
          LEFT JOIN purchase_order_items i ON i.order_id = o.id
              WHERE o.deleted_at IS NULL AND o.status IN ('draft','ordered','partial')
              GROUP BY o.id
              ORDER BY FIELD(o.status,'partial','ordered','draft'), o.desired_date, o.id
              LIMIT " . (int)$limit
        );
        foreach ($rows as &$r) {
            $r['id']             = (int)$r['id'];
            $r['item_count']     = (int)$r['item_count'];
            $r['received_count'] = (int)$r['received_count'];
            $r['rate'] = $r['item_count'] > 0 ? (int)floor($r['received_count'] * 100 / $r['item_count']) : 0;
        }
        return $rows;
    }

    /** 一覧用：発注IDごとの 品目数／納品済品目数 */
    public static function itemProgress(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($orderIds), '?'));
        $out = [];
        foreach (Db::all(
            "SELECT order_id, COUNT(*) AS item_count, SUM(received_qty >= qty) AS received_count
               FROM purchase_order_items WHERE order_id IN ($ph) GROUP BY order_id",
            array_map('intval', $orderIds)
        ) as $r) {
            $out[(int)$r['order_id']] = ['item_count' => (int)$r['item_count'], 'received_count' => (int)$r['received_count']];
        }
        return $out;
    }
}
