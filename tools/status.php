#!/usr/bin/env php
<?php
// Overworld status: compare layer sets with data folders, as an HTML page. Tables show
// which data file each layer uses, layers whose file is missing, files no layer uses,
// and entries the app ignores. Files are found the way the app finds them: data folders
// (roots) are searched in order and the first one holding a path wins, and every
// layers/*.json in a root is a layer set.
//
// Command line (PHP 7.2+), writing the page to stdout and a summary to stderr:
//   make status                                    status.html from ./config.json
//   php tools/status.php > status.html             the same
//   php tools/status.php DIR [DIR ...]             these data folders, in order
//   php tools/status.php --config deploy/config.json --docroot ~/site
//
//   --config FILE    read roots and layer_sets from an app config.json; relative paths
//                    are relative to its folder, /paths need --docroot, URLs are skipped
//   --docroot DIR    where /paths in a config point (the site's public_html)
//   --layers FILE    also check this layer set (repeatable)
//   --problems       leave out the table of matched files
//   --text           plain-text tables instead of HTML
//
//   Exit status is 1 when a layer entry has no file or is one the app ignores.
//
// On the server, deploy.sh installs this as public_html/overworld/status/index.php,
// so it is at /overworld/status. It reads the app's config.json and only runs behind
// a password (cPanel Directory Privacy on overworld/status), since it lists every file,
// including ones in protected folders. Add ?problems to leave out matched files.

// Must match parseLayersConfig in index.html: the first key present names the file.
const FILE_KEYS = ['tile_url', 'file_geojson', 'file_kml', 'file_csv', 'file_gpx',
                   'file_kmz', 'file_tif', 'file_geotiff', 'file'];
const BY_EXT = ['geojson', 'kml', 'kmz', 'gpx', 'csv', 'tif', 'tiff'];

// ---------------------------------------------------------------- gathering

// Every file under $dir as a sorted list of relative paths, skipping what the app's
// listing skips (dotfiles, _files.json, *.php). Follows links, once each.
function list_files($dir) {
    $files = [];
    $seen = [];
    $stack = [''];
    while ($stack) {
        $rel = array_pop($stack);
        $abs = $rel === '' ? $dir : "$dir/$rel";
        $real = realpath($abs);
        if ($real === false || isset($seen[$real])) continue;
        $seen[$real] = true;
        $names = @scandir($abs);
        if ($names === false) continue;
        foreach ($names as $name) {
            if ($name[0] === '.' || $name === '_files.json' || substr($name, -4) === '.php') continue;
            $path = $rel === '' ? $name : "$rel/$name";
            if (is_dir("$dir/$path")) $stack[] = $path;
            else $files[] = $path;
        }
    }
    sort($files);
    return $files;
}

// Whether any folder between $root and the file is password-protected (cPanel Directory
// Privacy puts AuthType in its .htaccess). _files.php leaves such folders out of the listing.
function in_protected_folder($root, $path) {
    $parts = explode('/', $path);
    array_pop($parts);
    $dir = $root;
    foreach ($parts as $p) {
        $dir .= '/' . $p;
        $h = "$dir/.htaccess";
        if (is_file($h) && stripos(file_get_contents($h), 'AuthType') !== false) return true;
    }
    return false;
}

// A root or layer-set path from a config, as a local path, or [null, why not].
function resolve_config_path($p, $base, $docroot) {
    if (strpos($p, '://') !== false) return [null, 'a URL; not checked'];
    if ($p !== '' && $p[0] === '/') {
        if ($docroot === null) return [null, 'a site path; give --docroot to check it'];
        return [rtrim($docroot, '/') . $p, null];
    }
    return [rtrim($base, '/') . '/' . $p, null];
}

// The app's layer id for a name (see parseLayersConfig).
function layer_id($name) {
    return preg_replace('/-+$/', '', preg_replace('/[^a-z0-9]+/', '-', strtolower($name)));
}

// What an entry points at: ['key' => ..., 'path' => ...], or null if no file key.
function entry_file($e) {
    foreach (FILE_KEYS as $k) {
        if (isset($e[$k]) && is_string($e[$k]) && $e[$k] !== '') return ['key' => $k, 'path' => $e[$k]];
    }
    return null;
}

// A table cell is a string, or ['t' => text, 'u' => url] for a link.
function cell_text($c) {
    return is_array($c) ? $c['t'] : (string)$c;
}

// The columns every layer table shares: name (linked to source_url when it is a web
// address), tags, layer set, and the entry's other keys. $f is the entry's file key,
// shown in its own column, so it is left out of "other".
function layer_cells($e, $label, $set, $f) {
    $src = isset($e['source_url']) && is_string($e['source_url']) ? trim($e['source_url']) : '';
    $isUrl = (bool)preg_match('#^https?://#i', $src);
    $other = [];
    if ($src !== '' && !$isUrl) $other[] = "source: $src";
    foreach ($e as $k => $v) {
        if (in_array($k, ['name', 'source_url', 'tags'], true) || ($f && $k === $f['key'])) continue;
        $other[] = "$k: " . (is_scalar($v) ? (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v)
                                           : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    $tags = isset($e['tags']) ? (is_array($e['tags']) ? implode(',', $e['tags']) : (string)$e['tags']) : '';
    return ['name' => $isUrl ? ['t' => $label, 'u' => $src] : $label,
            'tags' => implode(', ', array_filter(array_map('trim', explode(',', $tags)), 'strlen')),
            'set' => $set,
            'other' => implode('; ', $other)];
}

function sort_rows(&$rows, $keys) {
    usort($rows, function ($a, $b) use ($keys) {
        foreach ($keys as $k) {
            $c = strcmp(cell_text($a[$k]), cell_text($b[$k]));
            if ($c) return $c;
        }
        return 0;
    });
}

// $roots: [['label', 'dir']] in search order. $sets: [['label', 'path']] explicit layer sets.
function check($roots, $sets, $notes) {
    $r = ['notes' => $notes, 'roots' => [], 'sets' => [], 'matched' => [], 'missing' => [],
          'unused' => [], 'ignored' => [], 'remote' => [], 'warnings' => []];

    // which roots hold each logical path, in search order
    $where = [];
    foreach ($roots as $i => $root) {
        if (!is_dir($root['dir'])) {
            $r['notes'][] = "root {$root['label']}: not on this machine, skipped";
            continue;
        }
        $files = list_files($root['dir']);
        $r['roots'][] = ['label' => $root['label'], 'files' => count($files)];
        foreach ($files as $f) $where[$f][] = $i;
    }

    // layer sets: explicit ones, then each root's layers/*.json (first root wins)
    $setPaths = [];          // logical path => true, for files that are layer sets
    $loaded = [];            // realpath => true
    $all = [];
    foreach ($sets as $s) $all[] = $s;
    foreach ($where as $f => $idx) {
        if (preg_match('#^layers/[^/]+\.json$#', $f)) {
            $setPaths[$f] = true;
            // found in a root: labelled by its logical path, which is unique (first root wins)
            $all[] = ['label' => $f, 'path' => $roots[$idx[0]]['dir'] . '/' . $f];
        }
    }
    $entries = [];
    foreach ($all as $s) {
        $real = realpath($s['path']);
        if ($real === false) { $r['warnings'][] = "layer set {$s['label']}: not found"; continue; }
        if (isset($loaded[$real])) continue;
        $loaded[$real] = true;
        $data = json_decode(file_get_contents($real), true);
        if (!is_array($data) || ($data && array_keys($data) !== range(0, count($data) - 1))) {
            $r['warnings'][] = "layer set {$s['label']}: not a JSON list of layers"
                . (json_last_error() ? ' (' . json_last_error_msg() . ')' : '');
            continue;
        }
        $r['sets'][] = ['label' => $s['label'], 'entries' => count($data)];
        foreach ($data as $n => $e) $entries[] = ['set' => $s['label'], 'n' => $n, 'e' => $e];
    }

    // match entries to files
    $used = [];              // logical path => true
    $ids = [];               // layer id => [names with set]
    $byFile = [];            // logical path => [names]
    foreach ($entries as $x) {
        $e = $x['e'];
        if (!is_array($e)) {
            $r['ignored'][] = ['name' => "(entry #{$x['n']})", 'tags' => '', 'set' => $x['set'],
                               'other' => '', 'why' => 'not an object'];
            continue;
        }
        $name = isset($e['name']) && is_string($e['name']) ? $e['name'] : '';
        $f = entry_file($e);
        $L = layer_cells($e, $name !== '' ? $name : "(entry #{$x['n']}, no name)", $x['set'], $f);
        if ($f === null) {
            $r['ignored'][] = $L + ['why' => 'no file key'];
            continue;
        }
        if ($f['key'] === 'tile_url') {
            if ($name !== '') $r['remote'][] = $L + ['url' => $f['path']];
            else $r['ignored'][] = $L + ['why' => 'no name'];
            continue;
        }
        $used[$f['path']] = true;   // a file is "used" even by an entry the app skips
        $ext = strtolower(pathinfo($f['path'], PATHINFO_EXTENSION));
        if ($name === '') { $r['ignored'][] = $L + ['why' => 'no name']; continue; }
        if ($f['key'] === 'file' && !in_array($ext, BY_EXT, true)) {
            $r['ignored'][] = $L + ['why' => "unsupported file type .$ext ({$f['path']})"];
            continue;
        }
        $ids[layer_id($name)][] = "$name ({$x['set']})";
        $byFile[$f['path']][] = $name;
        if (isset($where[$f['path']])) {
            $idx = $where[$f['path']];
            $also = [];
            foreach (array_slice($idx, 1) as $i) $also[] = $roots[$i]['label'];
            $note = $also ? ['also in ' . implode(', ', $also)] : [];
            if (in_protected_folder($roots[$idx[0]]['dir'], $f['path']))
                $note[] = 'password-protected folder: the app sees it only if that folder has its own _files.json';
            $r['matched'][] = ['file' => $f['path'], 'root' => $roots[$idx[0]]['label']] + $L
                            + ['note' => implode('; ', $note)];
        } else {
            $r['missing'][] = $L + ['file' => $f['path']];
        }
    }

    foreach ($where as $f => $idx) {
        if (isset($used[$f]) || isset($setPaths[$f])) continue;
        $labels = [];
        foreach ($idx as $i) $labels[] = $roots[$i]['label'];
        $r['unused'][] = ['file' => $f, 'root' => implode(', ', $labels)];
    }

    foreach ($ids as $id => $names) {
        if (count($names) > 1) $r['warnings'][] = "layer id \"$id\" is shared by: " . implode('; ', $names)
            . ' (the app keeps only one; links with l= are ambiguous)';
    }
    foreach ($byFile as $f => $names) {
        if (count($names) > 1) $r['warnings'][] = "$f is used by more than one layer: " . implode('; ', $names);
    }

    sort_rows($r['matched'], ['file', 'name']);
    sort_rows($r['missing'], ['name']);
    sort_rows($r['remote'], ['name']);
    return $r;
}

// ---------------------------------------------------------------- output

// [title, [column key => heading], rows]
function sections($r, $problems) {
    $layer = ['name' => 'layer name', 'tags' => 'tags', 'set' => 'layer set'];
    $s = [];
    if (!$problems) {
        $s[] = ['Files used by layers', ['file' => 'file', 'root' => 'from root'] + $layer
                + ['other' => 'other', 'note' => 'note'], $r['matched']];
    }
    $s[] = ['Layers whose file is missing', $layer + ['file' => 'file', 'other' => 'other'], $r['missing']];
    $s[] = ['Files no layer uses', ['file' => 'file', 'root' => 'in root'], $r['unused']];
    $s[] = ['Layers the app ignores', $layer + ['why' => 'why', 'other' => 'other'], $r['ignored']];
    if (!$problems) {
        $s[] = ['Tile layers (remote, not checked)', $layer + ['url' => 'url', 'other' => 'other'], $r['remote']];
    }
    return $s;
}

function summary($r) {
    $n = function ($k, $one, $many) use ($r) { $c = count($r[$k]); return "$c " . ($c === 1 ? $one : $many); };
    return implode(', ', [$n('matched', 'file used', 'files used'),
                          $n('missing', 'layer missing its file', 'layers missing their file'),
                          $n('unused', 'file unused', 'files unused'),
                          $n('ignored', 'layer ignored', 'layers ignored')]);
}

function width($s) {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

// The columns of $head that have a value in at least one row (always the first).
function used_columns($head, $rows) {
    $out = [];
    $first = true;
    foreach ($head as $k => $label) {
        $keep = $first;
        foreach ($rows as $row) if (!$keep && cell_text($row[$k]) !== '') $keep = true;
        if ($keep) $out[$k] = $label;
        $first = false;
    }
    return $out;
}

function print_text($r, $problems) {
    foreach ($r['notes'] as $n) echo "note: $n\n";
    foreach ($r['roots'] as $x) echo "root       {$x['label']}  ({$x['files']} files)\n";
    foreach ($r['sets'] as $x) echo "layer set  {$x['label']}  ({$x['entries']} layers)\n";
    foreach (sections($r, $problems) as $sec) {
        list($title, $head, $rows) = $sec;
        echo "\n== $title (" . count($rows) . ")\n";
        if (!$rows) continue;
        $head = used_columns($head, $rows);
        $w = array_map('width', $head);
        foreach ($rows as $row) foreach ($head as $k => $_) $w[$k] = max($w[$k], width(cell_text($row[$k])));
        $line = function ($cells) use ($w) {
            $out = '';
            foreach ($w as $k => $n) $out .= $cells[$k] . str_repeat(' ', $n - width($cells[$k]) + 2);
            return rtrim($out) . "\n";
        };
        echo $line($head);
        echo $line(array_map(function ($n) { return str_repeat('-', $n); }, $w));
        foreach ($rows as $row) echo $line(array_map('cell_text', $row));
    }
    if ($r['warnings']) {
        echo "\n== Warnings (" . count($r['warnings']) . ")\n";
        foreach ($r['warnings'] as $wn) echo "- $wn\n";
    }
    echo "\n" . summary($r) . "\n";
}

// $web: served by the site, so the page can link to its ?problems variant
function print_html($r, $problems, $web) {
    $h = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    echo "<!doctype html><meta charset=utf-8><meta name=viewport content='width=device-width'>",
         "<title>Overworld status</title><style>",
         "body{font:14px system-ui,sans-serif;margin:16px;color:#111;background:#fff}",
         "table{border-collapse:collapse;margin:4px 0 20px}td,th{border:1px solid #ccc;padding:3px 8px;text-align:left;vertical-align:top}",
         "th{background:#f3f4f6}h2{font-size:16px;margin:20px 0 4px}.none,.when{color:#666}",
         "td{overflow-wrap:anywhere}",
         "@media(prefers-color-scheme:dark){body{color:#eee;background:#111}th{background:#222}td,th{border-color:#444}}",
         "</style><h1>Overworld status</h1><p class=when>", $h(date('Y-m-d H:i T')), '</p><p>', $h(summary($r)), '</p>';
    if ($web) echo $problems ? "<p><a href='?'>Show matched files too</a></p>" : "<p><a href='?problems'>Problems only</a></p>";
    echo '<ul>';
    foreach ($r['notes'] as $n) echo '<li>', $h($n), '</li>';
    foreach ($r['roots'] as $x) echo '<li>root <code>', $h($x['label']), '</code> (', $x['files'], ' files)</li>';
    foreach ($r['sets'] as $x) echo '<li>layer set <code>', $h($x['label']), '</code> (', $x['entries'], ' layers)</li>';
    echo '</ul>';
    if ($r['warnings']) {
        echo '<h2>Warnings (', count($r['warnings']), ')</h2><ul>';
        foreach ($r['warnings'] as $wn) echo '<li>', $h($wn), '</li>';
        echo '</ul>';
    }
    foreach (sections($r, $problems) as $sec) {
        list($title, $head, $rows) = $sec;
        echo '<h2>', $h($title), ' (', count($rows), ')</h2>';
        if (!$rows) { echo "<p class=none>none</p>"; continue; }
        $head = used_columns($head, $rows);
        echo '<table><tr>';
        foreach ($head as $c) echo '<th>', $h($c), '</th>';
        echo '</tr>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($head as $k => $_) {
                $c = $row[$k];
                echo '<td>', is_array($c)
                    ? "<a href='" . $h($c['u']) . "' target=_blank rel=noopener>" . $h($c['t']) . '</a>'
                    : $h($c), '</td>';
            }
            echo '</tr>';
        }
        echo '</table>';
    }
}

// ---------------------------------------------------------------- setup

// Roots and layer sets from an app config.json.
function from_config($file, $docroot, &$roots, &$sets, &$notes) {
    $cfg = json_decode(@file_get_contents($file), true);
    if (!is_array($cfg)) return "can't read config $file";
    $base = dirname(realpath($file));
    foreach (isset($cfg['roots']) ? $cfg['roots'] : [] as $root) {
        $p = is_array($root) ? (isset($root['url']) ? $root['url'] : '') : $root;
        list($dir, $why) = resolve_config_path($p, $base, $docroot);
        if ($dir === null) { $notes[] = "root $p: $why"; continue; }
        $roots[] = ['label' => $p, 'dir' => rtrim($dir, '/')];
    }
    foreach (isset($cfg['layer_sets']) && is_array($cfg['layer_sets']) ? $cfg['layer_sets'] : [] as $p) {
        list($path, $why) = resolve_config_path($p, $base, $docroot);
        if ($path === null) { $notes[] = "layer set $p: $why"; continue; }
        $sets[] = ['label' => $p, 'path' => $path];
    }
    return null;
}

function tilde($p) {
    $home = getenv('HOME');
    return $home && strpos($p, $home . '/') === 0 ? '~' . substr($p, strlen($home)) : $p;
}

function main_cli($argv) {
    $config = null; $docroot = null; $problems = false; $text = false; $dirs = []; $extra = [];
    for ($i = 1; $i < count($argv); $i++) {
        $a = $argv[$i];
        if ($a === '--problems') $problems = true;
        elseif ($a === '--text') $text = true;
        elseif (in_array($a, ['--config', '--docroot', '--layers'], true)) {
            if (!isset($argv[$i + 1])) { fwrite(STDERR, "$a needs a value\n"); return 2; }
            $v = $argv[++$i];
            if ($a === '--config') $config = $v;
            elseif ($a === '--docroot') $docroot = $v;
            else $extra[] = $v;
        } elseif ($a === '-h' || $a === '--help') {
            foreach (array_slice(file(__FILE__), 2) as $l) {   // the header comment
                if (strpos($l, '//') !== 0) break;
                echo preg_replace('#^// ?#', '', $l);
            }
            return 0;
        } elseif ($a !== '' && $a[0] === '-') { fwrite(STDERR, "unknown option $a (see --help)\n"); return 2; }
        else $dirs[] = $a;
    }
    $roots = []; $sets = []; $notes = [];
    if (!$dirs && $config === null) {
        $config = dirname(__DIR__) . '/config.json';
        if (!is_file($config)) { fwrite(STDERR, "no config.json; give data folders or --config (see --help)\n"); return 2; }
    }
    if ($config !== null) {
        $err = from_config($config, $docroot, $roots, $sets, $notes);
        if ($err) { fwrite(STDERR, "$err\n"); return 2; }
        $notes[] = 'using ' . tilde(realpath($config));
    }
    foreach ($dirs as $d) {
        if (!is_dir($d)) { fwrite(STDERR, "no such folder: $d\n"); return 2; }
        $roots[] = ['label' => tilde(rtrim(realpath($d), '/')) . '/', 'dir' => rtrim(realpath($d), '/')];
    }
    foreach ($extra as $p) $sets[] = ['label' => tilde($p), 'path' => $p];
    // labels end in / so "root" + "layers/x.json" reads as a path
    foreach ($roots as &$root) if (substr($root['label'], -1) !== '/') $root['label'] .= '/';
    unset($root);

    $r = check($roots, $sets, $notes);
    if ($text) {
        print_text($r, $problems);
    } else {
        print_html($r, $problems, false);
        fwrite(STDERR, summary($r) . "\n");
    }
    return ($r['missing'] || $r['ignored']) ? 1 : 0;
}

function main_web() {
    // Lists everything, including password-protected folders, so refuse to run unless
    // this page itself is behind a password.
    $user = '';
    foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER', 'PHP_AUTH_USER'] as $k) {
        if (!empty($_SERVER[$k])) { $user = $_SERVER[$k]; break; }
    }
    if ($user === '') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "This page lists every data file, so it only runs behind a password.\n",
             "Protect its folder with cPanel > Directory Privacy, then reload.\n";
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    // installed as <app>/status/index.php, next to the app's config.json
    $docroot = !empty($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : dirname(dirname(__DIR__));
    $roots = []; $sets = []; $notes = [];
    $err = from_config(dirname(__DIR__) . '/config.json', $docroot, $roots, $sets, $notes);
    if ($err) { http_response_code(500); echo htmlspecialchars("$err\n"); return; }
    foreach ($roots as &$root) if (substr($root['label'], -1) !== '/') $root['label'] .= '/';
    unset($root);
    print_html(check($roots, $sets, $notes), isset($_GET['problems']), true);
}

if (PHP_SAPI === 'cli') exit(main_cli($argv));
main_web();
