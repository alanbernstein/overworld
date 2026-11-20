<?php
// The data folder's file listing, standing in for tools/index-data.py on a host
// with no shell. data.htaccess routes <dir>/_files.json here as ?dir=<dir>/.
//
// Password-protected folders (cPanel Directory Privacy puts "AuthType" in their
// .htaccess) are never listed, and their contents never appear in a parent's
// listing; give such a folder a static _files.json instead (tools/index-data.py).

header('Content-Type: application/json');
header('Cache-Control: no-cache');

function deny($code) {
    http_response_code($code);
    echo "null\n";
    exit;
}

function is_protected($dir) {
    $h = $dir . '/.htaccess';
    return is_file($h) && stripos(file_get_contents($h), 'AuthType') !== false;
}

$base = realpath(__DIR__);
$root = realpath($base . '/' . (isset($_GET['dir']) ? $_GET['dir'] : ''));
if ($root === false || !is_dir($root)) deny(404);
if ($root !== $base && strpos($root, $base . DIRECTORY_SEPARATOR) !== 0) deny(404);

// refuse a protected folder, or anything inside one
for ($d = $root; strlen($d) >= strlen($base); $d = dirname($d)) {
    if (is_protected($d)) deny(403);
    if ($d === $base) break;
}

$files = array();
$stack = array('');
while ($stack) {
    $rel = array_pop($stack);
    $abs = $rel === '' ? $root : $root . '/' . $rel;
    foreach (scandir($abs) as $name) {
        if ($name[0] === '.' || $name === '_files.json' || substr($name, -4) === '.php') continue;
        $path = $rel === '' ? $name : $rel . '/' . $name;
        $full = $root . '/' . $path;
        if (is_dir($full)) {
            if (!is_protected($full)) $stack[] = $path;
        } else {
            $files[] = $path;
        }
    }
}
sort($files);
echo json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
