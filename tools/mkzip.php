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

// 打包前把 languages/*.po 编译为 .mo（仓库不入库 .mo，见 languages/.gitignore）。
// 缺了这步，干净检出构建的 zip 没有任何 .mo，翻译全部失效（3.2.1 修复的复发路径）。
if (is_dir($dir . '/languages')) {
    $i18n = __DIR__ . '/i18n.php';
    if (is_file($i18n)) {
        $dir_arg = $dir;
        // 与 tests/run.php 相同的子进程模式：Windows 中文路径下绝对路径经
        // cmd 代码页会乱码，优先转 ASCII 相对路径。
        $cwd_norm  = rtrim(str_replace('\\', '/', (string) getcwd()), '/') . '/';
        $i18n_norm = str_replace('\\', '/', $i18n);
        $i18n_arg  = (strpos($i18n_norm, $cwd_norm) === 0) ? substr($i18n_norm, strlen($cwd_norm)) : $i18n;
        $dir_norm  = str_replace('\\', '/', $dir_arg);
        $dir_arg   = (strpos($dir_norm, $cwd_norm) === 0) ? substr($dir_norm, strlen($cwd_norm)) : $dir_arg;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($i18n_arg) . ' compile-dir ' . escapeshellarg($dir_arg) . ' 2>&1', $mo_out, $mo_code);
        echo implode("\n", $mo_out), "\n";
        if (0 !== $mo_code) {
            exit("i18n compile-dir failed (exit {$mo_code})\n");
        }
    }
}

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
