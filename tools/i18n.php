<?php
/**
 * i18n 工具链（无 gettext 依赖）：
 *   php tools/i18n.php makepot  <plugin-dir> <text-domain> <pot-file>
 *   php tools/i18n.php update-po <pot-file> <po-file> [po-file ...]   # 补全缺失 msgid（msgstr 置空）
 *   php tools/i18n.php compile  <po-file>                              # po -> mo
 *
 * 支持：单复数均可（本项目源串目前无复数）；msgctxt；多行续行。
 * 注意：纯解析器，非完整 gettext 实现；转义支持 \" \' \\ \n \t。
 *
 * @package Moonlight_Shop
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$cmd  = isset($argv[1]) ? $argv[1] : '';
$args = array_slice($argv, 2);

switch ($cmd) {
    case 'makepot':
        makepot($args[0], $args[1], $args[2]);
        break;
    case 'update-po':
        update_po($args[0], array_slice($args, 1));
        break;
    case 'compile':
        foreach ($args as $po) {
            compile($po);
        }
        break;
    default:
        exit("Usage: php i18n.php makepot|update-po|compile ...\n");
}

/* ---------------- 提取源串 → POT ---------------- */

function makepot($plugin_dir, $domain, $pot_file)
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin_dir, FilesystemIterator::SKIP_DOTS));
    $strings = array();
    $re = '/(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e)\s*\(\s*((?:\'(?:[^\'\\\\]|\\\\.)*\')|(?:"(?:[^"\\\\]|\\\\.)*"))\s*,\s*(?:\'|")' . preg_quote($domain, '/') . '(?:\'|")\s*[\),]/s';
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        if (!preg_match_all($re, $code, $m)) {
            continue;
        }
        foreach ($m[1] as $raw) {
            $s = decode_php_string($raw);
            if ('' !== $s) {
                $strings[$s] = true;
            }
        }
    }
    ksort($strings, SORT_STRING);

    $out  = "# Moonlight Shop 翻译模板（由 tools/i18n.php 自动提取）。\n";
    $out .= "# 新增源串 msgstr 留空即回退英文/中文源串。\n";
    $out .= "msgid \"\"\nmsgstr \"\"\n";
    $out .= "\"Project-Id-Version: \\n\"\n\"Language: \\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\n";
    foreach (array_keys($strings) as $s) {
        $out .= 'msgid ' . encode_po($s) . "\nmsgstr \"\"\n\n";
    }
    file_put_contents($pot_file, $out);
    echo "POT: " . count($strings) . " strings -> {$pot_file}\n";
}

/* ---------------- 补全 PO ---------------- */

function update_po($pot_file, array $po_files)
{
    $pot = parse_po($pot_file);
    foreach ($po_files as $po_file) {
        $po  = parse_po($po_file);
        $add = 0;
        foreach ($pot as $id => $tr) {
            if (!array_key_exists($id, $po)) {
                $po[$id] = '';
                $add++;
            }
        }
        write_po($po_file, $po);
        echo "PO: +{$add} missing -> {$po_file}\n";
    }
}

/* ---------------- PO -> MO ---------------- */

function compile($po_file)
{
    $entries = parse_po($po_file);
    $mo_file = substr($po_file, 0, -3) . '.mo';

    // header（msgid ""）必须第一个
    ksort($entries, SORT_STRING);
    if (isset($entries[''])) {
        $entries = array('' => $entries['']) + $entries;
    }

    $ids = $strs = array();
    foreach ($entries as $id => $tr) {
        $ids[]  = $id;
        $strs[] = (string) $tr;
    }
    $n = count($ids);

    $ids_bin = $strs_bin = '';
    $o_table = $t_table = '';
    $offset = 28 + 16 * $n; // 头 7 个 u32 + 两张表
    // 先算 originals 块
    $base_ids = $offset;
    foreach ($ids as $id) {
        $b = $id . "\0";
        // GNU MO 的 off 是「绝对文件偏移」：base_ids + 当前数据块内游标
        // （此前写的是块内相对偏移，读方全部解析失败——第三个编译器 bug）
        $o_table .= pack('VV', strlen($id), $base_ids + strlen($ids_bin));
        $ids_bin .= $b;
    }
    $base_strs = $base_ids + strlen($ids_bin);
    foreach ($strs as $s) {
        $t_table .= pack('VV', strlen($s), $base_strs + strlen($strs_bin));
        $strs_bin .= $s . "\0";
    }

    // 布局（与 header 偏移一致）：[header 28][原文表 8n][译文表 8n][原文数据][译文数据]
    // header 第 5 字段 = 译文**表**偏移（28+8n），不是译文数据偏移。
    // 原实现此处错填 + 拼接顺序矛盾 + 大端 magic + 相对偏移，四 bug 叠加，
    // 本工具产出的 .mo 从未真正可用（bcb45923 的「复发」实为从未修好过）。
    $mo = pack('V7', 0x950412de, 0, $n, 28, 28 + 8 * $n, 0, 28 + 16 * $n)
        . $o_table . $t_table . $ids_bin . $strs_bin;
    file_put_contents($mo_file, $mo);
    echo "MO: {$n} entries -> {$mo_file}\n";
}

/* ---------------- PO 解析 / 序列化 ---------------- */

function parse_po($file)
{
    $text = file_get_contents($file);
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $entries = array();
    $state = 'idle'; // idle|id|str
    $cur_id = $cur_tr = '';
    $target = null; // 'id'|'str'
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line === '#') {
            if ($state === 'str') { flush_entry($entries, $cur_id, $cur_tr); $state = 'idle'; }
            continue;
        }
        if (0 === strpos($line, '#')) {
            continue; // 注释（含 #, #: 等）
        }
        if (0 === strpos($line, 'msgid ')) {
            if ($state === 'str') { flush_entry($entries, $cur_id, $cur_tr); }
            $cur_id = po_value($line, 'msgid'); $cur_tr = ''; $target = 'id'; $state = 'id';
            continue;
        }
        if (0 === strpos($line, 'msgstr ')) {
            $cur_tr = po_value($line, 'msgstr'); $target = 'str'; $state = 'str';
            continue;
        }
        if ($line[0] === '"' && $target !== null) {
            $part = decode_po_line($line);
            if ($target === 'id') { $cur_id .= $part; } else { $cur_tr .= $part; }
        }
    }
    if ($state === 'str') {
        flush_entry($entries, $cur_id, $cur_tr);
    }
    return $entries;
}

function flush_entry(array &$entries, $id, $tr)
{
    if ('' !== $id) {
        $entries[$id] = $tr;
    } else {
        $entries[''] = $tr; // header
    }
}

function po_value($line, $kw)
{
    $rest = trim(substr($line, strlen($kw)));
    return ('' === $rest) ? '' : decode_po_line($rest);
}

function decode_po_line($quoted)
{
    $quoted = trim($quoted);
    if (isset($quoted[0]) && $quoted[0] === '"') {
        $quoted = substr($quoted, 1, -1);
    }
    return str_replace(array('\\n', '\\t', '\\"', '\\\\'), array("\n", "\t", '"', '\\'), $quoted);
}

function encode_po($s)
{
    return '"' . str_replace(array('\\', '"', "\n", "\t"), array('\\\\', '\\"', '\\n', '\\t'), $s) . '"';
}

function write_po($file, array $entries)
{
    $header = isset($entries['']) ? $entries[''] : "Content-Type: text/plain; charset=UTF-8\n";
    $out = "msgid \"\"\nmsgstr \"\"\n" . preg_replace('/$/', '', $header) . "\n";
    // header 多行：把 header 字符串按行拆为续行引号串
    $header_lines = explode("\n", rtrim($header, "\n"));
    $out = "msgid \"\"\nmsgstr \"\"\n";
    foreach ($header_lines as $hl) {
        $out .= encode_po($hl . "\n") . "\n";
    }
    $out .= "\n";
    ksort($entries, SORT_STRING);
    foreach ($entries as $id => $tr) {
        if ('' === $id) {
            continue;
        }
        $out .= 'msgid ' . encode_po($id) . "\n";
        $out .= 'msgstr ' . encode_po((string) $tr) . "\n\n";
    }
    file_put_contents($file, $out);
}

/* ---------------- PHP 字符串字面量解码 ---------------- */

function decode_php_string($raw)
{
    $quote = $raw[0];
    $body  = substr($raw, 1, -1);
    if ($quote === "'") {
        return str_replace(array("\\'", '\\\\'), array("'", '\\'), $body);
    }
    return str_replace(array('\\"', '\\\\', '\\n', '\\t', '\\$'), array('"', '\\', "\n", "\t", '$'), $body);
}
