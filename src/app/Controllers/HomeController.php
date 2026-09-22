<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Db;
use App\Core\View;

class HomeController
{
    public static function index(): void
    {
        Auth::requireLogin();

        $counts = [
            'products'  => (int)Db::value('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL'),
            'parts'     => (int)Db::value('SELECT COUNT(*) FROM parts WHERE deleted_at IS NULL'),
            'materials' => (int)Db::value('SELECT COUNT(*) FROM materials WHERE deleted_at IS NULL'),
            'suppliers' => (int)Db::value('SELECT COUNT(*) FROM suppliers WHERE deleted_at IS NULL'),
            'draft_orders' => (int)Db::value("SELECT COUNT(*) FROM purchase_orders WHERE status = 'draft' AND deleted_at IS NULL"),
            'expiring'  => (int)Db::value('SELECT COUNT(*) FROM inventory WHERE expiry_date IS NOT NULL AND expiry_date <= ? AND qty > 0', [Clock::daysLater(5)]),
        ];

        View::render('home', [
            'counts'      => $counts,
            'week_start'  => Clock::today(),
            'server_time' => Clock::dt(Clock::now()),
            'db_time'     => Clock::dt((string)Db::value('SELECT NOW()')),
        ]);
    }
}
