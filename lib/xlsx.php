<?php
/**
 * Minimal XLSX writer. An .xlsx is a zip of XML parts, so no library is needed —
 * which matters on shared hosting where composer is not available.
 * Supports several sheets, a styled header row, wrapped text and column widths.
 */
class Xlsx {
    private $sheets = [];

    /** @param array $rows first row is the header; every cell is a string or number */
    public function sheet($name, array $rows, array $widths = [], array $wrapCols = []) {
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', (string)$name);
        $this->sheets[] = ['name' => mb_substr($name, 0, 31) ?: 'Sheet',
                           'rows' => $rows, 'widths' => $widths, 'wrap' => array_flip($wrapCols)];
        return $this;
    }

    private static function esc($s) {
        $s = (string)$s;
        /* Excel rejects most control characters outright. */
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
    private static function colName($i) {
        $s = '';
        for ($n = $i; $n >= 0; $n = intdiv($n, 26) - 1) { $s = chr(65 + $n % 26) . $s; }
        return $s;
    }

    private function sheetXml(array $sh) {
        $cols = '';
        if ($sh['widths']) {
            $cols = '<cols>';
            foreach ($sh['widths'] as $i => $w)
                $cols .= '<col min="' . ($i+1) . '" max="' . ($i+1) . '" width="' . (float)$w . '" customWidth="1"/>';
            $cols .= '</cols>';
        }
        $x = '';
        foreach ($sh['rows'] as $r => $row) {
            $isHead = ($r === 0);
            $h = $isHead ? ' ht="26" customHeight="1"' : '';
            $x .= '<row r="' . ($r+1) . '"' . $h . '>';
            foreach (array_values($row) as $c => $val) {
                $ref = self::colName($c) . ($r+1);
                $style = $isHead ? 1 : (isset($sh['wrap'][$c]) ? 2 : 3);
                if (is_int($val) || is_float($val)) {
                    $x .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $val . '</v></c>';
                } else {
                    $s = (string)$val;
                    if ($s === '') { $x .= '<c r="' . $ref . '" s="' . $style . '"/>'; continue; }
                    $x .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
                        . self::esc($s) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
             . $cols . '<sheetData>' . $x . '</sheetData></worksheet>';
    }

    private function stylesXml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3">'
        . '<font><sz val="10"/><name val="Arial"/></font>'
        . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
        . '<font><sz val="10"/><color rgb="FF333333"/><name val="Arial"/></font>'
        . '</fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F3864"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right>'
        . '<top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top"/></xf>'
        . '</cellXfs></styleSheet>';
    }

    /** Writes the file and returns its path. */
    public function save($path) {
        if (!class_exists('ZipArchive')) throw new RuntimeException('The zip extension is required to build .xlsx files');
        @unlink($path);
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::CREATE) !== true) throw new RuntimeException('Could not create ' . $path);

        $n = count($this->sheets);
        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++)
            $types .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $types .= '</Types>';
        $z->addFromString('[Content_Types].xml', $types);

        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $sh) {
            $id = $i + 1;
            $wb .= '<sheet name="' . self::esc($sh['name']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
            $z->addFromString('xl/worksheets/sheet' . $id . '.xml', $this->sheetXml($sh));
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $z->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $z->addFromString('xl/styles.xml', $this->stylesXml());
        $z->close();
        return $path;
    }
}
