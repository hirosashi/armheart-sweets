<?php
namespace App\Core;

use SimpleXMLElement;
use ZipArchive;

/** Excel（.xlsx）の読み書き。外部ライブラリを使わず ZipArchive と XML で扱う */
class Xlsx
{
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    /** 展開後に読む1ファイルの上限（圧縮で小さく見せた巨大なXMLを読まない） */
    private const MAX_XML_BYTES = 50 * 1024 * 1024;

    /**
     * シートごとの行を .xlsx に書き出し、作った一時ファイルのパスを返す。
     * 1行目は見出しとして太字・色付き・固定表示にする。
     *
     * @param array<string, list<list<string|int|float|null>>> $sheets シート名 => 行
     */
    public static function write(array $sheets): string
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'xlsx');
        $zip  = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Excelファイルを作れませんでした。');
        }
        $names = array_keys($sheets);

        $types = '';
        $wbSheets = '';
        $wbRels = '';
        foreach ($names as $i => $name) {
            $n = $i + 1;
            $types    .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbSheets .= '<sheet name="' . self::esc((string)$name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $wbRels   .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', self::sheetXml($sheets[$name]));
        }
        $styleRel = count($names) + 1;

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $types . '</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="' . self::NS_REL . '">'
            . '<sheets>' . $wbSheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $wbRels
            . '<Relationship Id="rId' . $styleRel . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Yu Gothic"/></font><font><b/><sz val="11"/><name val="Yu Gothic"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFDDE5F0"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');
        $zip->close();
        return $path;
    }

    /**
     * .xlsx を読み、シート名 => [Excelの行番号 => セルの文字列の並び] を返す。
     *
     * @return array<string, array<int, list<string>>>
     */
    public static function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Excelファイル（.xlsx）として開けませんでした。');
        }
        try {
            $wb   = self::xml($zip, 'xl/workbook.xml');
            $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
            if ($wb === null || $rels === null) {
                throw new \RuntimeException('Excelファイル（.xlsx）として読めませんでした。');
            }
            $targets = [];
            foreach ($rels->Relationship as $r) {
                $targets[(string)$r['Id']] = (string)$r['Target'];
            }
            $shared = [];
            $ss = self::xml($zip, 'xl/sharedStrings.xml');
            if ($ss !== null) {
                foreach ($ss->si as $si) {
                    $shared[] = self::text($si);
                }
            }

            $out = [];
            foreach ($wb->sheets->sheet as $s) {
                $rid    = (string)$s->attributes(self::NS_REL)['id'];
                $target = $targets[$rid] ?? '';
                $file   = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                $sheet  = $target === '' ? null : self::xml($zip, $file);
                $out[(string)$s['name']] = $sheet === null ? [] : self::rows($sheet, $shared);
            }
            return $out;
        } finally {
            $zip->close();
        }
    }

    /** @param list<list<string|int|float|null>> $rows */
    private static function sheetXml(array $rows): string
    {
        $widths = [];
        $data   = '';
        foreach ($rows as $ri => $row) {
            $r = $ri + 1;
            $cells = '';
            foreach (array_values($row) as $ci => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $ref   = self::colName($ci) . $r;
                $style = $ri === 0 ? ' s="1"' : '';
                if (is_int($value) || is_float($value)) {
                    $cells .= '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
                    $len = strlen((string)$value);
                } else {
                    $cells .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc($value) . '</t></is></c>';
                    $len = mb_strwidth($value);
                }
                $widths[$ci] = max($widths[$ci] ?? 0, $len);
            }
            $data .= '<row r="' . $r . '">' . $cells . '</row>';
        }

        $cols = '';
        foreach ($widths as $ci => $w) {
            $n = $ci + 1;
            $cols .= '<col min="' . $n . '" max="' . $n . '" width="' . min(60, max(8, $w + 2)) . '" customWidth="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . ($cols === '' ? '' : '<cols>' . $cols . '</cols>')
            . '<sheetData>' . $data . '</sheetData></worksheet>';
    }

    /**
     * @param list<string> $shared
     * @return array<int, list<string>>
     */
    private static function rows(SimpleXMLElement $sheet, array $shared): array
    {
        $rows = [];
        $next = 1;
        foreach ($sheet->sheetData->row as $row) {
            $rowNo = isset($row['r']) ? (int)$row['r'] : $next;
            $next  = $rowNo + 1;
            $cells = [];
            $ci    = 0;
            foreach ($row->c as $c) {
                if (isset($c['r'])) {
                    $ci = self::colIndex((string)$c['r']);
                }
                $type = (string)$c['t'];
                $raw  = (string)$c->v;
                $cells[$ci] = match ($type) {
                    's'         => $shared[(int)$raw] ?? '',
                    'inlineStr' => self::text($c->is),
                    'str', 'e'  => $raw,
                    'b'         => $raw === '1' ? '1' : '0',
                    default     => self::number($raw),
                };
                $ci++;
            }
            if ($cells === []) {
                continue;
            }
            $line = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $i => $v) {
                $line[$i] = $v;
            }
            $rows[$rowNo] = $line;
        }
        return $rows;
    }

    /** 数値セルの誤差（0.90000000000000002 など）を丸めて文字列にする */
    private static function number(string $raw): string
    {
        if (!is_numeric($raw)) {
            return $raw;
        }
        $f = (float)$raw;
        if (floor($f) === $f && abs($f) < 1e15) {
            return (string)(int)$f;
        }
        return (string)round($f, 10);
    }

    private static function text(SimpleXMLElement $node): string
    {
        if (isset($node->t)) {
            return (string)$node->t;
        }
        $s = '';
        foreach ($node->r as $run) {
            $s .= (string)$run->t;
        }
        return $s;
    }

    private static function xml(ZipArchive $zip, string $name): ?SimpleXMLElement
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ($stat['size'] > self::MAX_XML_BYTES) {
            throw new \RuntimeException('Excelファイルの中身が大きすぎて読めません。');
        }
        $data = $zip->getFromName($name, self::MAX_XML_BYTES);
        if ($data === false) {
            return null;
        }
        $xml = simplexml_load_string($data, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        return $xml === false ? null : $xml;
    }

    private static function colName(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }
        return $name;
    }

    private static function colIndex(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
        $n = 0;
        foreach (str_split((string)$letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }

    private static function esc(string $s): string
    {
        $s = (string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
