<?php
/* ============================================================
   VISITOR COUNTER — M. Abhishek & Associates
   ------------------------------------------------------------
   A real counter that lives on your own hosting. No third party,
   no API key, nothing secret in the public HTML.

   HOW IT WORKS
     - Counts one visit per browser per day (a unique-visitor style
       count), using a hash of IP + user agent + date. The raw IP is
       never stored — only an irreversible hash — so this does not
       build a log of who visited.
     - Stores two numbers in counter.json beside this file.
     - Returns JSON: {"value": 1234, "metric": "visitors"}

   INSTALL (cPanel)
     1. Upload counter.php into public_html, next to index.html
     2. Create an empty file counter.json in the same folder and set
        its permissions to 644 (or let this script create it)
     3. Make sure the folder is writable by PHP (usually 755)
     4. Visit https://yourdomain.in/counter.php once — you should see
        JSON with a number in it
     5. In index.html find SITE_ANALYTICS and set:
            endpoint: 'https://yourdomain.in/counter.php'
            field:    'value'
            metric:   'visitors'

   If anything fails, the script returns the last known value and the
   page falls back to "Analytics connection pending". It never shows
   an invented number.
   ============================================================ */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');   // allows www and non-www to read it

$file = __DIR__ . '/counter.json';
$salt = 'CHANGE-THIS-TO-ANY-RANDOM-STRING-ONCE';  // makes the hash unguessable

// ---- read current state -------------------------------------------------
$state = ['visitors' => 0, 'pageviews' => 0, 'seen' => []];
if (is_readable($file)) {
    $raw = @file_get_contents($file);
    $decoded = @json_decode($raw, true);
    if (is_array($decoded)) { $state = array_merge($state, $decoded); }
}

// ---- identify this visit ------------------------------------------------
$ip  = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? 'unknown';
$ip  = trim(explode(',', $ip)[0]);
$ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
$day = gmdate('Y-m-d');
$key = hash('sha256', $salt . '|' . $ip . '|' . $ua . '|' . $day);

// skip obvious crawlers so the figure means something
$isBot = $ua === '' || preg_match('/bot|crawl|spider|slurp|bing|yandex|duckduck|facebookexternalhit|preview|monitor|curl|wget|headless/i', $ua);

if (!$isBot) {
    $state['pageviews'] = (int)$state['pageviews'] + 1;

    if (!isset($state['seen'][$key])) {
        $state['visitors'] = (int)$state['visitors'] + 1;
        $state['seen'][$key] = $day;
    }

    // keep only today's and yesterday's keys so the file stays small
    $keep = [$day, gmdate('Y-m-d', strtotime('-1 day'))];
    foreach ($state['seen'] as $k => $d) {
        if (!in_array($d, $keep, true)) { unset($state['seen'][$k]); }
    }

    // atomic write, with a lock so two visitors at once cannot corrupt it
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, json_encode($state), LOCK_EX) !== false) {
        @rename($tmp, $file);
    }
}

echo json_encode([
    'value'  => (int)$state['visitors'],
    'metric' => 'visitors',
    'views'  => (int)$state['pageviews']
]);
