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
//   php tools/status.php --config ~/site/overworld/config.json --docroot ~/site
//
//   --config FILE    read roots and layer_sets from an app config.json; relative paths
//                    are relative to its folder, /paths need --docroot, URLs are skipped
//   --docroot DIR    where /paths in a config point (the site's public_html)
//   --layers FILE    also check this layer set (repeatable)
//   --problems       leave out the informational tables (matched files, tile layers)
//   --text           plain-text tables instead of HTML
//
//   Findings are listed by severity: errors (a layer won't appear), warnings (likely
//   mistakes), then information. Exit status is 1 when there is an error.
//
// On the server, deploy.sh installs this as public_html/overworld/status/index.php,
// so it is at /overworld/status. It reads the app's config.json and only runs behind
// a password (cPanel Directory Privacy on overworld/status), since it lists every file,
// including ones in protected folders. Add ?problems to leave out the informational tables.

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

// 1234567 => "1.2 MB" (powers of 1000, as macOS Finder shows sizes)
function human_size($bytes) {
    if ($bytes < 1000) return "$bytes B";
    $units = ['kB', 'MB', 'GB', 'TB'];
    $v = $bytes;
    foreach ($units as $u) {
        $v /= 1000;
        // decide on the rounded value, so 999,999 B is "1.0 MB", not "1000 kB"
        $digits = round($v, 1) < 10 ? 1 : 0;
        if (round($v, $digits) < 1000 || $u === 'TB') return number_format($v, $digits, '.', '') . " $u";
    }
}

// A table cell is a string, or an array: ['t' => text, 'u' => url] for a link, or
// ['t' => text, 'b' => bytes] for a size, which sorts by the byte count.
function size_cell($bytes) {
    return ['t' => human_size($bytes), 'b' => $bytes];
}

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
    $r = ['roots' => [], 'sets' => [], 'matched' => [], 'missing' => [],
          'unused' => [], 'ignored' => [], 'remote' => [], 'errors' => [], 'warnings' => [], 'info' => $notes];

    // which roots hold each logical path, in search order, and each copy's size
    $where = [];
    $sizes = [];             // root index => [logical path => bytes]
    foreach ($roots as $i => $root) {
        if (!is_dir($root['dir'])) {
            $r['info'][] = "root {$root['label']}: not on this machine, skipped";
            continue;
        }
        $files = list_files($root['dir']);
        $bytes = 0;
        foreach ($files as $f) {
            $where[$f][] = $i;
            $sizes[$i][$f] = (int)@filesize($root['dir'] . '/' . $f);
            $bytes += $sizes[$i][$f];
        }
        $r['roots'][] = ['label' => $root['label'], 'files' => count($files), 'size' => human_size($bytes)];
    }

    // layer sets: explicit ones, then each root's layers/*.json (first root wins)
    $setPaths = [];          // logical path => true, for files that are layer sets
    $loaded = [];            // realpath => true
    $all = [];
    foreach ($sets as $s) $all[] = $s;
    foreach ($where as $f => $idx) {
        if (preg_match('#^layers/[^/]+\.json$#', $f)) {
            $setPaths[$f] = true;
            // found in a root: labelled root + path, so a copy of an explicit set (say
            // layers/example.json in both the app and a data folder) reads as a different file
            $all[] = ['label' => $roots[$idx[0]]['label'] . $f, 'path' => $roots[$idx[0]]['dir'] . '/' . $f];
        }
    }
    $entries = [];
    foreach ($all as $s) {
        $real = realpath($s['path']);
        if ($real === false) { $r['errors'][] = "layer set {$s['label']}: not found"; continue; }
        if (isset($loaded[$real])) continue;
        $loaded[$real] = true;
        $data = json_decode(file_get_contents($real), true);
        if (!is_array($data) || ($data && array_keys($data) !== range(0, count($data) - 1))) {
            $r['errors'][] = "layer set {$s['label']}: not a JSON list of layers"
                . (json_last_error() ? ' (' . json_last_error_msg() . ')' : '');
            continue;
        }
        $r['sets'][] = ['label' => $s['label'], 'entries' => count($data)];
        foreach ($data as $n => $e) $entries[] = ['set' => $s['label'], 'n' => $n, 'e' => $e];
    }

    // where a missing file should go: the first root on this machine
    $home = null;
    foreach ($roots as $i => $root) if (is_dir($root['dir'])) { $home = $i; break; }

    // match entries to files
    $used = [];              // logical path => true
    $ids = [];               // layer id => [['name', 'set']]
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
        $ids[layer_id($name)][] = ['name' => $name, 'set' => $x['set']];
        $byFile[$f['path']][] = $name;
        if (isset($where[$f['path']])) {
            $idx = $where[$f['path']];
            $also = [];
            foreach (array_slice($idx, 1) as $i) $also[] = $roots[$i]['label'];
            $note = $also ? ['also in ' . implode(', ', $also)] : [];
            if (in_protected_folder($roots[$idx[0]]['dir'], $f['path'])) {
                $note[] = 'password-protected folder';
                $r['warnings'][] = "$name: {$f['path']} is in a password-protected folder; the app sees it "
                    . 'only if that folder has its own _files.json (tools/index-data.py)';
            }
            $r['matched'][] = ['file' => $f['path'], 'size' => size_cell($sizes[$idx[0]][$f['path']]),
                               'root' => $roots[$idx[0]]['label']] + $L
                            + ['note' => implode('; ', $note)];
        } else {
            $dir = dirname($f['path']);
            $up = ($home !== null ? $roots[$home]['label'] : '') . ($dir === '.' ? '' : "$dir/");
            $r['missing'][] = $L + ['file' => $f['path'], 'fix' => "upload to $up (or fix the path)"];
        }
    }

    foreach ($where as $f => $idx) {
        if (isset($used[$f]) || isset($setPaths[$f])) continue;
        $labels = [];
        foreach ($idx as $i) $labels[] = $roots[$i]['label'];
        $r['unused'][] = ['file' => $f, 'size' => size_cell($sizes[$idx[0]][$f]), 'root' => implode(', ', $labels)];
    }

    foreach ($ids as $id => $names) {
        if (count($names) > 1) $r['warnings'][] = "duplicate layer \"{$names[0]['name']}\": defined "
            . count($names) . ' times, in ' . implode(', ', array_column($names, 'set'))
            . " (id \"$id\"). The app keeps only one, and links with l= are ambiguous; remove the extra copies.";
    }
    foreach ($byFile as $f => $names) {
        // the same file under different names is deliberate (e.g. one track file, several
        // heatmap filters); the same name twice is a duplicate, reported above
        if (count($names) > 1 && count(array_unique($names)) === count($names))
            $r['info'][] = "$f is shared by " . count($names) . ' layers: ' . implode('; ', $names);
    }

    sort_rows($r['matched'], ['file', 'name']);
    sort_rows($r['missing'], ['name']);
    sort_rows($r['remote'], ['name']);
    return $r;
}

// ---------------------------------------------------------------- output

const LEVELS = ['error' => 'Errors', 'warning' => 'Warnings', 'info' => 'Information'];

// Message lists and tables, grouped by severity. A table is [title, [column key =>
// heading], rows, note]; $problems leaves out the informational tables.
function report($r, $problems) {
    $layer = ['name' => 'layer name', 'tags' => 'tags', 'set' => 'layer set'];
    $info = [['Files no layer uses', ['file' => 'file', 'size' => 'size', 'root' => 'in root'], $r['unused'],
              'not an error: leftovers, or files a layer will use later']];
    if (!$problems) {
        $info[] = ['Files used by layers', ['file' => 'file', 'size' => 'size', 'root' => 'from root'] + $layer
                   + ['other' => 'other', 'note' => 'note'], $r['matched'], ''];
        $info[] = ['Tile layers (remote, not checked)', $layer + ['url' => 'url', 'other' => 'other'], $r['remote'], ''];
    }
    return [
        'error' => [$r['errors'], [
            ['Layers whose file is missing', $layer + ['file' => 'file', 'fix' => 'fix', 'other' => 'other'],
             $r['missing'], 'these layers show greyed out, or not at all when hide_unavailable is on'],
            ['Layers the app ignores', $layer + ['why' => 'why', 'other' => 'other'], $r['ignored'],
             'these entries never appear in the app'],
        ]],
        'warning' => [$r['warnings'], []],
        'info' => [$r['info'], $info],
    ];
}

// Number of findings at each level: messages plus table rows (info counts used files).
function counts($r) {
    return ['error' => count($r['errors']) + count($r['missing']) + count($r['ignored']),
            'warning' => count($r['warnings']),
            'ok' => count($r['matched']) + count($r['remote'])];
}

function summary($r) {
    $c = counts($r);
    $p = function ($n, $one, $many) { return "$n " . ($n === 1 ? $one : $many); };
    return implode(', ', [$p($c['error'], 'error', 'errors'), $p($c['warning'], 'warning', 'warnings'),
                          $p($c['ok'], 'layer OK', 'layers OK'), $p(count($r['unused']), 'file unused', 'files unused')]);
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
    $tag = ['error' => 'ERROR', 'warning' => 'WARN ', 'info' => 'info '];
    echo summary($r), "\n";
    foreach ($r['roots'] as $x) echo "root       {$x['label']}  ({$x['files']} files, {$x['size']})\n";
    foreach ($r['sets'] as $x) echo "layer set  {$x['label']}  ({$x['entries']} layers)\n";
    foreach (report($r, $problems) as $level => $group) {
        list($messages, $tables) = $group;
        foreach ($messages as $m) echo "{$tag[$level]}  $m\n";
        foreach ($tables as $t) {
            list($title, $head, $rows) = $t;
            if (!$rows && $level !== 'info') continue;
            echo "\n== ", $level === 'info' ? '' : strtoupper($level) . ': ', "$title (" . count($rows) . ")\n";
            if (!$rows) continue;
            $head = used_columns($head, $rows);
            $w = array_map('width', $head);
            foreach ($rows as $row) foreach ($head as $k => $_) $w[$k] = max($w[$k], width(cell_text($row[$k])));
            $line = function ($cells) use ($w) {
                $out = '';
                foreach ($w as $k => $n) {
                    $pad = str_repeat(' ', $n - width($cells[$k]));
                    $out .= ($k === 'size' ? $pad . $cells[$k] : $cells[$k] . $pad) . '  ';
                }
                return rtrim($out) . "\n";
            };
            echo $line($head);
            echo $line(array_map(function ($n) { return str_repeat('-', $n); }, $w));
            foreach ($rows as $row) echo $line(array_map('cell_text', $row));
        }
        echo "\n";
    }
}

function html_table($h, $head, $rows) {
    $head = used_columns($head, $rows);
    // explicit thead/tbody, so the sort script moves only the body rows
    $out = '<div class=scroll><table><thead><tr>';
    foreach ($head as $k => $c) $out .= ($k === 'size' ? '<th data-numeric>' : '<th>') . $h($c) . '</th>';
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $out .= '<tr>';
        foreach ($head as $k => $_) {
            $c = $row[$k];
            if (isset($c['u'])) {
                $out .= "<td><a href='" . $h($c['u']) . "' target=_blank rel=noopener>" . $h($c['t']) . '</a></td>';
            } elseif (isset($c['b'])) {
                $out .= '<td class=num data-sort="' . $c['b'] . '">' . $h($c['t']) . '</td>';
            } else {
                if (in_array($k, ['file', 'set', 'root', 'url'], true)) {
                    // paths break after a slash rather than mid-name
                    $out .= '<td class=mono>' . str_replace('/', '/<wbr>', $h(cell_text($c))) . '</td>';
                } else {
                    $out .= ($k === 'fix' ? '<td class=fix>' : '<td>') . $h(cell_text($c)) . '</td>';
                }
            }
        }
        $out .= '</tr>';
    }
    return $out . '</tbody></table></div>';
}

// $web: served by the site, so the page can link to its ?problems variant
function print_html($r, $problems, $web) {
    $h = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $c = counts($r);
    $state = $c['error'] ? 'error' : ($c['warning'] ? 'warning' : 'ok');
    $title = $c['error'] ? "{$c['error']} error" . ($c['error'] > 1 ? 's' : '')
           : ($c['warning'] ? "{$c['warning']} warning" . ($c['warning'] > 1 ? 's' : '') : 'all OK');
    echo "<!doctype html><html lang=en><meta charset=utf-8><meta name=viewport content='width=device-width,initial-scale=1'>",
         '<title>', $h("Overworld status · $title"), '</title><style>', STATUS_CSS, '</style>',
         "<header class=$state><h1>Overworld status</h1><div class=badges>";
    foreach ([['error', 'error', 'errors'], ['warning', 'warning', 'warnings'], ['ok', 'layer OK', 'layers OK']] as $b) {
        $n = $c[$b[0]];
        echo "<span class='badge ", $b[0], $n ? '' : ' zero', "'>", $n, ' ', $n === 1 ? $b[1] : $b[2], '</span>';
    }
    echo '<span class=when>', $h(date('Y-m-d H:i T')), '</span>';
    if ($web) echo $problems ? " <a href='?'>show everything</a>" : '';
    echo '</div></header><main>';

    foreach (report($r, $problems) as $level => $group) {
        list($messages, $tables) = $group;
        $tables_with_rows = array_filter($tables, function ($t) { return $t[2]; });
        echo "<section class='level $level'><h2><span class=chip>", LEVELS[$level], '</span>';
        if ($level !== 'info') {
            $n = $level === 'error' ? $c['error'] : $c['warning'];
            echo ' <span class=count>', $n ?: 'none', '</span>';
        }
        echo '</h2>';
        if ($level === 'info') {
            echo '<ul class=meta>';
            foreach ($r['roots'] as $x) echo '<li>data folder <code>', $h($x['label']), '</code> ', $x['files'], ' files, ', $x['size'], '</li>';
            foreach ($r['sets'] as $x) echo '<li>layer set <code>', $h($x['label']), '</code> ', $x['entries'], ' layers</li>';
            echo '</ul>';
        }
        if ($messages) {
            echo '<ul class=messages>';
            foreach ($messages as $m) echo '<li>', $h($m), '</li>';
            echo '</ul>';
        }
        foreach ($level === 'info' ? $tables : $tables_with_rows as $t) {
            list($title, $head, $rows, $note) = $t;
            $label = '<h3>' . $h($title) . ' <span class=count>' . count($rows) . '</span>'
                   . ($note ? ' <span class=note>— ' . $h($note) . '</span>' : '') . '</h3>';
            if ($level === 'info') {
                // collapsed: open it when you need the detail
                echo '<details><summary>', $label, '</summary>', $rows ? html_table($h, $head, $rows) : '<p class=none>none</p>', '</details>';
            } else {
                echo $label, html_table($h, $head, $rows);
            }
        }
        echo '</section>';
    }
    echo '</main>', SORT_SCRIPT;
}

// Compact, dense, readable in light and dark. Severity is carried by text (chips,
// headings) as well as colour.
const STATUS_CSS = <<<'CSS'
:root{color-scheme:light dark;--bg:#fff;--fg:#1b1f24;--muted:#667085;--line:#e4e7ec;--head:#f5f6f8;--hover:#f0f4ff;
--link:#0a58ca;--visited:#6639ba;--code:#eef0f3;
--err:#b42318;--err-bg:#fef3f2;--warn:#93500a;--warn-bg:#fffaeb;--ok:#067647;--ok-bg:#ecfdf3;--info:#475467;--info-bg:#f5f6f8}
@media(prefers-color-scheme:dark){:root{--bg:#0f1115;--fg:#e7e9ee;--muted:#98a0ad;--line:#2a2f39;--head:#171a21;--hover:#1b2130;
--link:#8ab8ff;--visited:#cba6ff;--code:#1f2430;
--err:#ff8f85;--err-bg:#2b1517;--warn:#ffc864;--warn-bg:#2a2111;--ok:#6ee0a0;--ok-bg:#10261a;--info:#aab3c0;--info-bg:#171a21}}
*{box-sizing:border-box}
body{margin:0;font:13px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--fg);background:var(--bg)}
a{color:var(--link);text-decoration:none}a:visited{color:var(--visited)}a:hover{text-decoration:underline}
code,.mono{font:12px/1.4 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
code{background:var(--code);padding:0 4px;border-radius:3px}
header{display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;padding:10px 16px;border-bottom:1px solid var(--line);
  border-top:3px solid var(--ok);position:sticky;top:0;background:var(--bg);z-index:2}
header.error{border-top-color:var(--err)}header.warning{border-top-color:var(--warn)}
h1{font-size:15px;margin:0;font-weight:650}
.badges{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
.badge{font-weight:600;font-size:12px;padding:1px 8px;border-radius:999px;border:1px solid currentColor}
.badge.error{color:var(--err);background:var(--err-bg)}.badge.warning{color:var(--warn);background:var(--warn-bg)}
.badge.ok{color:var(--ok);background:var(--ok-bg)}.badge.zero{opacity:.45}
.when{color:var(--muted);font-size:12px}
main{padding:4px 16px 24px}
section.level{margin:12px 0;padding:6px 0 4px 12px;border-left:3px solid var(--info)}
section.error{border-left-color:var(--err)}section.warning{border-left-color:var(--warn)}
h2{font-size:14px;margin:0 0 4px;display:flex;align-items:center;gap:8px}
.chip{font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:1px 7px;border-radius:4px;
  color:var(--info);background:var(--info-bg)}
.error .chip{color:var(--err);background:var(--err-bg)}.warning .chip{color:var(--warn);background:var(--warn-bg)}
h2 .count{color:var(--muted);font-weight:500;font-size:13px}
h3{display:inline;font-size:13px;margin:0;font-weight:600}
h3 .count{color:var(--muted);font-weight:500}.note{color:var(--muted);font-weight:400;font-size:12px}
section:not(.info) h3{display:block;margin:8px 0 2px}
ul.messages,ul.meta{margin:2px 0 6px;padding-left:18px}ul.messages li{margin:2px 0}
.error ul.messages li::marker{color:var(--err)}.warning ul.messages li::marker{color:var(--warn)}
ul.meta{color:var(--muted);list-style:none;padding:0}ul.meta li{display:inline-block;margin-right:16px}
details{margin:2px 0}summary{cursor:pointer;padding:2px 0}summary::marker{color:var(--muted)}
.scroll{overflow-x:auto;margin:2px 0 10px;border:1px solid var(--line);border-radius:6px}
table{border-collapse:collapse;width:100%;font-size:12.5px}
th,td{padding:3px 8px;text-align:left;vertical-align:top;border-bottom:1px solid var(--line)}
tbody tr:last-child td{border-bottom:0}tbody tr:hover{background:var(--hover)}
th{background:var(--head);font-weight:600;color:var(--muted);white-space:nowrap;cursor:pointer;user-select:none}
th:hover{color:var(--fg)}th[aria-sort=ascending]::after{content:' \25B2'}th[aria-sort=descending]::after{content:' \25BC'}
td{overflow-wrap:anywhere}td.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.error td.fix{color:var(--err)}
.none{color:var(--muted);margin:2px 0 8px}
CSS;

// Click a column heading to sort its table; click again to reverse. Sizes sort by
// their byte count (data-sort) and start largest first; text sorts naturally
// ("file2" before "file10"). Inline, so the saved status.html sorts offline too.
const SORT_SCRIPT = <<<'JS'
<script>
document.querySelectorAll('table').forEach(table => {
  const heads = [...table.tHead.rows[0].cells];
  heads.forEach((th, col) => {
    th.tabIndex = 0;
    const sort = () => {
      const numeric = th.hasAttribute('data-numeric');
      const dir = th.getAttribute('aria-sort') === (numeric ? 'descending' : 'ascending')
        ? (numeric ? 'ascending' : 'descending') : (numeric ? 'descending' : 'ascending');
      const key = td => numeric ? Number(td.dataset.sort) : td.textContent.trim();
      const rows = [...table.tBodies[0].rows];
      rows.sort((a, b) => {
        const x = key(a.cells[col]), y = key(b.cells[col]);
        const c = numeric ? x - y : x.localeCompare(y, undefined, { numeric: true, sensitivity: 'base' });
        return dir === 'ascending' ? c : -c;
      });
      table.tBodies[0].append(...rows);
      heads.forEach(h => h.removeAttribute('aria-sort'));
      th.setAttribute('aria-sort', dir);
    };
    th.addEventListener('click', sort);
    th.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); } });
  });
});
</script>
JS;

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
    return counts($r)['error'] ? 1 : 0;
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
