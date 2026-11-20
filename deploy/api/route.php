<?php
// OpenRouteService directions proxy, so the API key stays on the server.
// The app POSTs {"coordinates": [[lng, lat], [lng, lat]]} to route.php?profile=<p>
// (config.json "ors_proxy"); this forwards it with the key and returns the geojson.
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

// Accept only a plain two-or-more point route and rebuild the request from it,
// so nothing else is passed through to ORS.
$body = json_decode(file_get_contents('php://input', false, null, 0, 10000), true);
$coords = isset($body['coordinates']) ? $body['coordinates'] : null;
if (!is_array($coords) || count($coords) < 2 || count($coords) > 10) fail(400, 'bad coordinates');
foreach ($coords as $c) {
    if (!is_array($c) || count($c) !== 2 || !is_numeric($c[0]) || !is_numeric($c[1])) fail(400, 'bad coordinates');
}

$keyFile = getenv('HOME') . '/secrets/ors-key.txt';
if (!is_readable($keyFile) && isset($_SERVER['DOCUMENT_ROOT']))
    $keyFile = dirname($_SERVER['DOCUMENT_ROOT']) . '/secrets/ors-key.txt';
$key = is_readable($keyFile) ? trim(file_get_contents($keyFile)) : '';
if ($key === '') fail(500, 'routing key not installed');

$ch = curl_init("https://api.openrouteservice.org/v2/directions/$profile/geojson");
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array('coordinates' => $coords)),
    CURLOPT_HTTPHEADER => array('Authorization: ' . $key, 'Content-Type: application/json'),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
));
$resp = curl_exec($ch);
if ($resp === false) fail(502, 'routing service unreachable');
http_response_code(curl_getinfo($ch, CURLINFO_HTTP_CODE));
echo $resp;
