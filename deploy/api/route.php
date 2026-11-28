<?php
// OpenRouteService proxy, so the API key stays on the server (config.json "ors_proxy").
// Two services, each with its request rebuilt from checked values:
//   route.php?profile=<p>                      directions: POST {"coordinates": [[lng, lat], ...]}
//   route.php?service=isochrones&profile=<p>   travel-time areas: POST {"locations": [[lng, lat]],
//                                              "range": [seconds, ...]} (1-3 ranges, 1 min to 1 h)
// It forwards the request with the key and returns ORS's geojson.
//
// The key is read from ~/secrets/ors-key.txt (outside public_html, uploaded once
// with File Manager, never committed).

header('Content-Type: application/json');

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(array('error' => $msg)), "\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST only');

// Only this site's pages may use it. Browsers always send Origin on a POST
// fetch; this stops other sites from spending the quota, not a determined script.
// Compare host[:port], which is what HTTP_HOST holds.
$origin = isset($_SERVER['HTTP_ORIGIN']) ? parse_url($_SERVER['HTTP_ORIGIN']) : false;
$originHost = isset($origin['host']) ? $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : '') : '';
if ($originHost === '' || strcasecmp($originHost, $_SERVER['HTTP_HOST']) !== 0) fail(403, 'forbidden');

$profiles = array('cycling-regular', 'cycling-road', 'cycling-mountain', 'cycling-electric',
                  'foot-walking', 'foot-hiking', 'driving-car', 'wheelchair');
$profile = isset($_GET['profile']) ? $_GET['profile'] : '';
if (!in_array($profile, $profiles, true)) fail(400, 'unknown profile');

$service = isset($_GET['service']) ? $_GET['service'] : 'directions';
if (!in_array($service, array('directions', 'isochrones'), true)) fail(400, 'unknown service');

function check_points($pts, $min, $max) {
    if (!is_array($pts) || count($pts) < $min || count($pts) > $max) fail(400, 'bad coordinates');
    foreach ($pts as $c) {
        if (!is_array($c) || count($c) !== 2 || !is_numeric($c[0]) || !is_numeric($c[1])) fail(400, 'bad coordinates');
    }
    return array_map(function ($c) { return array((float)$c[0], (float)$c[1]); }, $pts);
}

// Accept only the expected shape and rebuild the request from it, so nothing else
// is passed through to ORS.
$body = json_decode(file_get_contents('php://input', false, null, 0, 10000), true);
if ($service === 'directions') {
    $request = array('coordinates' => check_points(isset($body['coordinates']) ? $body['coordinates'] : null, 2, 10));
    $url = "https://api.heigit.org/openrouteservice/v2/directions/$profile/geojson";
} else {
    $range = isset($body['range']) ? $body['range'] : null;
    if (!is_array($range) || count($range) < 1 || count($range) > 3) fail(400, 'bad range');
    foreach ($range as $r) if (!is_numeric($r) || $r < 60 || $r > 3600) fail(400, 'bad range');
    $request = array('locations' => check_points(isset($body['locations']) ? $body['locations'] : null, 1, 1),
                     'range' => array_map('intval', $range), 'range_type' => 'time');
    $url = "https://api.heigit.org/openrouteservice/v2/isochrones/$profile";
}

$keyFile = getenv('HOME') . '/secrets/ors-key.txt';
if (!is_readable($keyFile) && isset($_SERVER['DOCUMENT_ROOT']))
    $keyFile = dirname($_SERVER['DOCUMENT_ROOT']) . '/secrets/ors-key.txt';
$key = is_readable($keyFile) ? trim(file_get_contents($keyFile)) : '';
if ($key === '') fail(500, 'routing key not installed');

$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($request),
    CURLOPT_HTTPHEADER => array('Authorization: ' . $key, 'Content-Type: application/json',
                                'Accept: application/json, application/geo+json'),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
));
$resp = curl_exec($ch);
if ($resp === false) fail(502, 'routing service unreachable');
http_response_code(curl_getinfo($ch, CURLINFO_HTTP_CODE));
echo $resp;
