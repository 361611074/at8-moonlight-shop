<?php
/**
 * 构建可安装的插件 zip（正斜杠条目 + 顶层插件目录）。
 * 用法: php tools/mkzip.php <plugin-dir> <output.zip>
 *
 * 为什么不用 PowerShell Compress-Archive / .NET ZipFile：
 * .NET Framework 的 ZipFile 会写入反斜杠分隔符，Linux 解压后文件名里
 * 带反斜杠，WordPress 激活时报「插件文件不存在」。
 *
 * @package Moonlight_Shop
 */

if (PHP_SAPI !== 'cli' || $argc < 3) {
    exit("Usage: php mkzip.php <plugin-dir> <output.zip>\n");
}
if (!class_exists('ZipArchive')) {
    exit("PHP zip extension required\n");
}

$dir = realpath($argv[1]);
$out = $argv[2];
$base = basename($dir);
$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    exit("Cannot create $out\n");
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $relative = substr($file->getPathname(), strlen($dir) + 1);
    $zip->addFile($file->getPathname(), $base . '/' . str_replace('\\', '/', $relative));
}
$zip->close();
echo "OK $out (" . filesize($out) . " bytes)\n";
