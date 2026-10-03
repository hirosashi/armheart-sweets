<?php
namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\OperationLog;
use App\Core\Session;
use App\Core\View;
use App\Core\Xlsx;
use App\Services\MasterIo;

class MasterIoController
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** Excelで取り込み・書き出し */
    public static function index(): void
    {
        Auth::requireLogin();

        $result = Session::get('master_io_result');
        Session::set('master_io_result', null);

        View::render('master_io/index', [
            'sheets'  => MasterIo::sheetNames(),
            'counts'  => MasterIo::counts(),
            'result'  => is_array($result) ? $result : null,
            'canEdit' => Auth::can('master'),
        ]);
    }

    /** 今の登録内容を1つのExcelファイルに書き出す */
    public static function export(): void
    {
        Auth::requireLogin();

        $path = Xlsx::write(MasterIo::export());
        $name = '登録データ_' . str_replace('-', '', Clock::today()) . '.xlsx';
        OperationLog::write('export', 'master_io', null, 'Excelに書き出しました');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"master.xlsx\"; filename*=UTF-8''" . rawurlencode($name));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        unlink($path);
        exit;
    }

    /** Excelの内容を取り込む（まちがいが1つでもあれば何も登録しない） */
    public static function import(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        if (!Auth::can('master')) {
            Session::flash('warn', 'この操作をする権限がありません。');
            App::redirect('/master-io');
        }

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $tooBig = is_array($file) && in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            Session::flash('warn', $tooBig ? 'ファイルが大きすぎます（5MBまで）。' : 'Excelファイルを選んでください。');
            App::redirect('/master-io');
        }
        if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            Session::flash('warn', 'Excelファイル（.xlsx）を選んでください。古い形式（.xls）は、Excelで「.xlsx」として保存しなおしてください。');
            App::redirect('/master-io');
        }
        if ((int)$file['size'] > self::MAX_BYTES) {
            Session::flash('warn', 'ファイルが大きすぎます（5MBまで）。');
            App::redirect('/master-io');
        }

        try {
            $book = Xlsx::read((string)$file['tmp_name']);
        } catch (\RuntimeException $e) {
            Session::flash('warn', $e->getMessage());
            App::redirect('/master-io');
        }

        $pdo = Db::conn();
        $pdo->beginTransaction();
        try {
            $result = MasterIo::import($book);
            if ($result['errors'] === []) {
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Session::set('master_io_result', $result + ['file' => (string)$file['name']]);
        if ($result['errors'] === []) {
            OperationLog::write('import', 'master_io', null, 'Excelから取り込みました：' . $file['name']);
            Session::flash('info', 'Excelの内容を取り込みました。');
        } else {
            Session::flash('warn', 'まちがいがあったため、何も登録していません。下の一覧を直してから、もう一度取り込んでください。');
        }
        App::redirect('/master-io');
    }
}
