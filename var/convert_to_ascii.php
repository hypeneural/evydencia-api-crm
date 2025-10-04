<?php
if ($argc < 2) {
    fwrite(STDERR, "usage: php convert.php <path>\n");
    exit(1);
}
$path = $argv[1];
$content = file_get_contents($path);
if ($content === false) {
    fwrite(STDERR, "unable to read {$path}\n");
    exit(1);
}
$converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $content);
if ($converted === false) {
    fwrite(STDERR, "iconv failed\n");
    exit(1);
}
file_put_contents($path, $converted);
