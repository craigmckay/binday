<?php
/**
 * binday - next Angus Council bin collection.
 *
 * Reads live from the MyAngus API (no database), caching the result to a local
 * JSON file so the council's server only gets hit a few times a day.
 *
 * Drop this on a cPanel host alongside the bin_*.png files. Needs PHP 7.0+.
 */

// ---------------------------------------------------------------- config ---

const CACHE_TTL  = 6 * 60 * 60;          // seconds - 6 hours
const TIMEZONE   = 'Europe/London';
const REFRESH    = 3600;                 // browser auto-refresh, seconds (0 = off)

// The chosen address is remembered in a cookie - there's no hardcoded UPRN, so a
// first-time visitor is asked for their postcode.
const COOKIE_UPRN  = 'binday_uprn';
const COOKIE_LABEL = 'binday_address';
const COOKIE_DAYS  = 365;

const HOST = 'https://myangus.angus.gov.uk';
const LOOKUP_COLLECTIONS   = '66587d491feab';   // UPRN + date -> collection dates
const LOOKUP_ADDRESSES     = '65a5507c8d3e6';   // postcode    -> addresses + UPRN
const LOOKUP_FORMAT_SEARCH = '686cdfffd9945';   // raw search  -> normalised search

// Where the bin images live, relative to this file.
const IMG_DIR = 'img';

// Display order + image for each bin stream the API can return.
const BINS = [
    'Grey'   => 'bin_grey.png',
    'Green'  => 'bin_green.png',
    'Purple' => 'bin_purple.png',
    'Blue'   => 'bin_blue.png',
    'Brown'  => 'bin_brown.png',
];

// ------------------------------------------------------------ http helper ---
//
// IMPORTANT: the API hands out a session cookie on the auth call and *requires*
// it back on the lookup. Passing the sid query parameter alone returns HTTP 200
// with an empty result set - which looks like "no collections" rather than an
// error. So every request below shares one cookie jar.

const USER_AGENT = 'Mozilla/5.0 (compatible; binday/1.0)';

function cookie_jar()
{
    static $jar = null;
    if ($jar === null) {
        $jar = tempnam(sys_get_temp_dir(), 'binday_');
        register_shutdown_function(function () use ($jar) { @unlink($jar); });
    }
    return $jar;
}

/** Cookies captured from the stream-wrapper fallback path. */
function stream_cookies($set = null)
{
    static $cookies = '';
    if ($set !== null) {
        $cookies = $set;
    }
    return $cookies;
}

function http_request($url, array $body = null)
{
    $isPost  = $body !== null;
    $payload = $isPost ? json_encode($body) : null;

    if (function_exists('curl_init')) {
        $ch   = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => USER_AGENT,
            CURLOPT_COOKIEJAR      => cookie_jar(),   // save cookies
            CURLOPT_COOKIEFILE     => cookie_jar(),   // and send them back
        ];
        if ($isPost) {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $payload;
            $opts[CURLOPT_HTTPHEADER] = [
                'Content-Type: application/json',
                'X-Requested-With: XMLHttpRequest',
                'Referer: ' . HOST . '/AchieveForms/',
            ];
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            throw new RuntimeException('curl: ' . $err);
        }
        return $out;
    }

    // Fallback: no cURL. Track cookies by hand.
    $headers = "User-Agent: " . USER_AGENT . "\r\n";
    if (stream_cookies() !== '') {
        $headers .= "Cookie: " . stream_cookies() . "\r\n";
    }
    $http = ['timeout' => 20, 'ignore_errors' => true, 'header' => $headers];
    if ($isPost) {
        $http['method']   = 'POST';
        $http['content']  = $payload;
        $http['header']  .= "Content-Type: application/json\r\n"
                          . "X-Requested-With: XMLHttpRequest\r\n"
                          . "Referer: " . HOST . "/AchieveForms/\r\n";
    }

    $out = @file_get_contents($url, false, stream_context_create(['http' => $http]));
    if ($out === false) {
        throw new RuntimeException('request failed');
    }

    // Harvest Set-Cookie from the response for the next call.
    if (isset($http_response_header)) {
        $jar = stream_cookies() !== '' ? explode('; ', stream_cookies()) : [];
        foreach ($http_response_header as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) {
                $pair = trim(explode(';', substr($h, 11))[0]);
                if ($pair !== '') {
                    $jar[] = $pair;
                }
            }
        }
        stream_cookies(implode('; ', array_unique($jar)));
    }

    return $out;
}

function http_get($url)            { return http_request($url); }
function http_post_json($url, $b)  { return http_request($url, $b); }

// -------------------------------------------------------------- angus api ---

function angus_session()
{
    $url = HOST . '/authapi/isauthenticated?' . http_build_query([
        'uri'             => HOST . '/AchieveForms/',
        'hostname'        => 'myangus.angus.gov.uk',
        'withCredentials' => 'true',
    ]);
    $data = json_decode(http_get($url), true);
    if (empty($data['auth-session'])) {
        throw new RuntimeException('no session token returned');
    }
    return $data['auth-session'];
}

/** @return array list of ['date' => 'Y-m-d', 'bin' => 'Green', 'frequency' => '...'] */
function angus_collections($uprn, $fromDate)
{
    $sid = angus_session();

    $url = HOST . '/apibroker/runLookup?' . http_build_query([
        'id'            => LOOKUP_COLLECTIONS,
        'repeat_against'=> '',
        'noRetry'       => 'true',
        'getOnlyTokens' => 'undefined',
        'log_id'        => '',
        'app_name'      => 'AF-Renderer::Self',
        '_'             => (string) round(microtime(true) * 1000),
        'sid'           => $sid,
    ]);

    $raw = http_post_json($url, ['formValues' => ['Section 3' => [
        'serviceUPRN' => ['value' => (string) $uprn],
        'currentDate' => ['value' => $fromDate],
    ]]]);

    $data = json_decode($raw, true);
    $rows = $data['integration']['transformed']['rows_data'] ?? [];
    if (!is_array($rows)) {
        $rows = [];
    }

    $out = [];
    foreach ($rows as $row) {
        if (empty($row['binDate']) || empty($row['binTypeList'])) {
            continue;
        }
        $out[] = [
            'date'      => $row['binDate'],
            'bin'       => $row['binTypeList'],
            'frequency' => trim($row['binCollected'] ?? ''),
        ];
    }
    return $out;
}

/** Shared plumbing for a runLookup call - returns rows_data as a list. */
function angus_lookup($lookupId, array $fields, $sid = null)
{
    $sid = $sid ?: angus_session();

    $url = HOST . '/apibroker/runLookup?' . http_build_query([
        'id'             => $lookupId,
        'repeat_against' => '',
        'noRetry'        => 'true',
        'getOnlyTokens'  => 'undefined',
        'log_id'         => '',
        'app_name'       => 'AF-Renderer::Self',
        '_'              => (string) round(microtime(true) * 1000),
        'sid'            => $sid,
    ]);

    $body = [];
    foreach ($fields as $k => $v) {
        $body[$k] = ['value' => (string) $v];
    }

    $data = json_decode(http_post_json($url, ['formValues' => ['Section 3' => $body]]), true);
    $rows = $data['integration']['transformed']['rows_data'] ?? [];
    return is_array($rows) ? array_values($rows) : [];
}

/**
 * Postcode or partial address -> addresses.
 * @return array list of ['uprn' => '117060380', 'display' => '7 PARK GROVE ...']
 */
function angus_addresses($search)
{
    $sid = angus_session();

    // The form normalises the search string first; mirror it for fidelity.
    $fmt = angus_lookup(LOOKUP_FORMAT_SEARCH, ['search' => $search], $sid);
    $normalised = $fmt[0]['formatted_search'] ?? $search;

    $rows = angus_lookup(LOOKUP_ADDRESSES, ['formatted_search' => $normalised], $sid);

    $out = [];
    foreach ($rows as $row) {
        if (empty($row['UPRN']) || empty($row['display'])) {
            continue;
        }
        $out[] = ['uprn' => $row['UPRN'], 'display' => $row['display']];
    }
    return $out;
}

// ----------------------------------------------------------------- caching ---

/** One cache file per address, so several households can share a deployment. */
function cache_file($uprn)
{
    return __DIR__ . '/.binday-cache-' . preg_replace('/\D/', '', $uprn) . '.json';
}

function load_collections($uprn, $today)
{
    $file = cache_file($uprn);

    // Fresh cache for the right day? Use it.
    if (is_readable($file)) {
        $cached = json_decode(file_get_contents($file), true);
        $fresh  = isset($cached['fetched']) && (time() - $cached['fetched']) < CACHE_TTL;
        if ($fresh && ($cached['uprn'] ?? null) === $uprn && ($cached['from'] ?? null) === $today) {
            return [$cached['collections'], true, null];
        }
    }

    try {
        $collections = angus_collections($uprn, $today);

        // An empty result means something went wrong upstream (bad UPRN, missing
        // session cookie, API change) - never cache it, or the page shows
        // "no collections" for the next six hours.
        if (!$collections) {
            throw new RuntimeException('API returned no collections for UPRN ' . $uprn);
        }

        @file_put_contents($file, json_encode([
            'fetched'     => time(),
            'uprn'        => $uprn,
            'from'        => $today,
            'collections' => $collections,
        ]), LOCK_EX);
        return [$collections, false, null];
    } catch (Exception $e) {
        // API down - fall back to a stale cache rather than showing nothing.
        if (isset($cached['collections'])) {
            return [$cached['collections'], true, $e->getMessage()];
        }
        return [[], false, $e->getMessage()];
    }
}

// ------------------------------------------------------- address selection ---

function remember($name, $value)
{
    $secure  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);
    $expires = time() + COOKIE_DAYS * 86400;
    $path    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';

    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $value, [
            'expires'  => $expires, 'path' => $path, 'secure' => $secure,
            'httponly' => true,     'samesite' => 'Lax',
        ]);
    } else {
        setcookie($name, $value, $expires, $path, '', $secure, true);
    }
}

function forget($name)
{
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    setcookie($name, '', time() - 3600, $path);
}

date_default_timezone_set(TIMEZONE);
$today = date('Y-m-d');

$view        = 'bins';      // bins | ask | pick
$addresses   = [];
$searchTerm  = '';
$pickError   = null;

$uprn  = preg_replace('/\D/', '', $_COOKIE[COOKIE_UPRN] ?? '');
$label = $_COOKIE[COOKIE_LABEL] ?? '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {

    // Address chosen from the dropdown - save and redirect (POST/redirect/GET,
    // so a refresh doesn't resubmit the form).
    if (!empty($_POST['uprn'])) {
        $chosen = preg_replace('/\D/', '', $_POST['uprn']);
        if ($chosen !== '') {
            remember(COOKIE_UPRN, $chosen);
            remember(COOKIE_LABEL, substr(trim($_POST['label'] ?? ''), 0, 120));
            header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? './', '?'), true, 303);
            exit;
        }
        $pickError = 'That address didn\'t come through - try again.';
        $view = 'ask';

    // Postcode submitted - look up the matching addresses.
    } elseif (isset($_POST['postcode'])) {
        $searchTerm = trim(substr($_POST['postcode'], 0, 60));
        $view = 'ask';
        if ($searchTerm === '') {
            $pickError = 'Enter a postcode or part of an address.';
        } else {
            try {
                $addresses = angus_addresses($searchTerm);
                if ($addresses) {
                    $view = 'pick';
                } else {
                    $pickError = 'No addresses found for "' . $searchTerm . '".';
                }
            } catch (Exception $e) {
                $pickError = 'Address lookup failed: ' . $e->getMessage();
            }
        }
    }

} elseif (isset($_GET['change'])) {
    $view = 'ask';
    forget(COOKIE_UPRN);
    forget(COOKIE_LABEL);
    $uprn = '';

} elseif ($uprn === '') {
    $view = 'ask';
}

$collections = [];
$fromCache   = false;
$error       = null;

if ($view === 'bins') {
    list($collections, $fromCache, $error) = load_collections($uprn, $today);
}

// Group bins by date, keep dates in order.
$byDate = [];
foreach ($collections as $c) {
    $byDate[$c['date']][] = $c['bin'];
}
ksort($byDate);

// Next collection = earliest date that isn't in the past.
$nextDate = null;
$nextBins = [];
foreach ($byDate as $date => $bins) {
    if ($date >= $today) {
        $nextDate = $date;
        $nextBins = $bins;
        break;
    }
}

// Order bins consistently and drop anything we've no image for.
$nextBins = array_values(array_filter(array_keys(BINS), function ($b) use ($nextBins) {
    return in_array($b, $nextBins, true);
}));

// Human phrasing for the headline.
$dayMessage = null;
$daysAway   = null;
if ($nextDate !== null) {
    $daysAway = (int) floor((strtotime($nextDate) - strtotime($today)) / 86400);
    if ($daysAway <= 0) {
        $dayMessage = 'TODAY';
    } elseif ($daysAway === 1) {
        $dayMessage = 'TOMORROW';
    } else {
        $dayMessage = strtoupper(date('l', strtotime($nextDate)));
    }
}

// The collections after this one, for the small print.
$upcoming = [];
foreach ($byDate as $date => $bins) {
    if ($nextDate !== null && $date > $nextDate) {
        $ordered = array_values(array_filter(array_keys(BINS), function ($b) use ($bins) {
            return in_array($b, $bins, true);
        }));
        $upcoming[] = ['date' => $date, 'bins' => $ordered];
    }
}
$upcoming = array_slice($upcoming, 0, 3);

function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if (REFRESH > 0 && $view === 'bins'): ?>
<meta http-equiv="refresh" content="<?= REFRESH ?>">
<?php endif; ?>
<meta name="theme-color" content="#121815">

<!-- Desktop browsers -->
<link rel="icon" href="favicon.ico" sizes="16x16 24x24 32x32 48x48 64x64 128x128 256x256">
<link rel="icon" type="image/png" sizes="192x192" href="<?= IMG_DIR ?>/ico/icon-192.png">

<!-- iOS home screen. Safari ignores .ico here and won't render transparency,
     so this is an opaque PNG; iOS rounds the corners itself. -->
<link rel="apple-touch-icon" sizes="180x180" href="<?= IMG_DIR ?>/ico/apple-touch-icon.png">
<meta name="apple-mobile-web-app-title" content="Bin Day">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

<!-- Android / Chrome -->
<meta name="mobile-web-app-capable" content="yes">
<link rel="manifest" href="site.webmanifest">
<title><?= ($view === 'bins' && $dayMessage) ? 'Bins out ' . e($dayMessage) : 'Bin collection' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Biryani:wght@200;700;900&display=swap">
<style>
  :root {
    --ink: #16241f;
    --muted: #6b7d76;
    --bg: #f4f6f5;
    --card: #ffffff;
  }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    padding: clamp(1rem, 4vw, 3rem) clamp(1rem, 4vw, 3rem) 3rem;
    font-family: "Biryani", system-ui, -apple-system, "Segoe UI", sans-serif;
    font-weight: 200;
    color: var(--ink);
    background: var(--bg);
    -webkit-text-size-adjust: 100%;
  }

  main {
    max-width: 1100px;
    margin: 0 auto;
  }

  h1 {
    font-weight: 200;
    font-size: clamp(1.75rem, 7vw, 4rem);
    line-height: 1.15;
    margin: 0 0 .25em;
    text-wrap: balance;
  }

  h1 strong { font-weight: 900; }

  .date-line {
    color: var(--muted);
    font-size: clamp(.95rem, 2.6vw, 1.35rem);
    margin: 0 0 clamp(1.5rem, 5vw, 2.5rem);
  }

  /* Bins ---------------------------------------------------------------- */

  .bins {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    align-items: flex-end;
    gap: clamp(.5rem, 3vw, 2.5rem);
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .bins li {
    text-align: center;
    flex: 0 1 auto;
  }

  .bins figure { margin: 0; }

  /* The bin photos were shot on white, so they sit on a light tile rather than
     directly on the page background. Keeps the edges clean in dark mode too. */
  .bin-tile {
    display: flex;
    align-items: flex-end;
    justify-content: center;
    background: #e7e7e7;
    border-radius: clamp(14px, 3vw, 26px);
    padding: clamp(.6rem, 2.2vw, 1.4rem);
    box-shadow: inset 0 0 0 1px rgba(0,0,0,.04), 0 6px 16px rgba(0,0,0,.10);
  }

  .bins img {
    display: block;
    width: clamp(110px, 26vw, 260px);
    height: auto;
  }

  .bins figcaption {
    margin-top: .5rem;
    font-size: clamp(.8rem, 2.2vw, 1.05rem);
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--muted);
  }

  /* Upcoming ------------------------------------------------------------ */

  .upcoming {
    margin: clamp(2.5rem, 8vw, 4rem) auto 0;
    max-width: 620px;
    background: var(--card);
    border-radius: 14px;
    padding: 1.25rem 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.07);
  }

  .upcoming h2 {
    font-size: .8rem;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--muted);
    margin: 0 0 .85rem;
  }

  .upcoming ul { margin: 0; padding: 0; list-style: none; }

  .upcoming li {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    align-items: center;
    justify-content: space-between;
    padding: .55rem 0;
    border-top: 1px solid #eef1f0;
    font-size: clamp(.9rem, 2.4vw, 1.05rem);
  }

  .upcoming li:first-child { border-top: 0; }

  .chips { display: flex; gap: .35rem; flex-wrap: wrap; }

  .chip {
    display: inline-block;
    width: 1.1em;
    height: 1.1em;
    border-radius: 4px;
    border: 1px solid rgba(0,0,0,.18);
  }

  .chip-grey   { background: #8a8f8c; }
  .chip-green  { background: #3b8a3f; }
  .chip-purple { background: #8d4bcf; }
  .chip-blue   { background: #0055c7; }
  .chip-brown  { background: #7a4a22; }

  /* Address picker -------------------------------------------------------- */

  .setup {
    max-width: 560px;
    margin: 0 auto;
    background: var(--card);
    border-radius: 16px;
    padding: clamp(1.25rem, 4vw, 2rem);
    box-shadow: 0 1px 3px rgba(0,0,0,.07);
  }

  .setup label {
    display: block;
    font-weight: 700;
    font-size: .95rem;
    margin-bottom: .5rem;
  }

  .setup input[type=text],
  .setup select {
    width: 100%;
    font-family: inherit;
    font-size: 1.05rem;
    padding: .7rem .85rem;
    border: 1px solid #c6cfca;
    border-radius: 10px;
    background: #fff;
    color: #16241f;
  }

  .setup button {
    margin-top: .9rem;
    width: 100%;
    font-family: inherit;
    font-size: 1.05rem;
    font-weight: 700;
    padding: .75rem 1rem;
    border: 0;
    border-radius: 10px;
    background: #14a12b;
    color: #fff;
    cursor: pointer;
  }

  .setup button:hover { background: #118924; }

  .setup .hint {
    margin: .8rem 0 0;
    font-size: .85rem;
    color: var(--muted);
  }

  .setup a { color: inherit; }

  .setup .error {
    margin: 0 0 1rem;
    padding: .7rem .9rem;
    border-radius: 8px;
    background: #fdecea;
    color: #8a1c11;
    font-size: .9rem;
  }

  footer {
    margin-top: 2rem;
    text-align: center;
    font-size: .8rem;
    color: var(--muted);
  }

  footer a { color: inherit; }

  .notice {
    max-width: 620px;
    margin: 0 auto 2rem;
    padding: .9rem 1.1rem;
    border-radius: 10px;
    background: #fff4e5;
    color: #7a4a00;
    font-size: .9rem;
  }

  @media (prefers-color-scheme: dark) {
    :root { --ink: #eef2f0; --muted: #93a49d; --bg: #121815; --card: #1b2320; }
    .upcoming li { border-top-color: #263029; }
    .notice { background: #3a2a10; color: #f0d9ae; }
  }
</style>
</head>
<body>
<main>

<?php if ($view === 'ask'): ?>

  <h1>Which <strong>address</strong>?</h1>
  <p class="date-line">Angus Council collections. We'll remember your choice on this device.</p>

  <div class="setup">
    <?php if ($pickError !== null): ?>
      <p class="error"><?= e($pickError) ?></p>
    <?php endif; ?>
    <form method="post" action="">
      <label for="postcode">Postcode or part of an address</label>
      <input type="text" id="postcode" name="postcode" value="<?= e($searchTerm) ?>"
             placeholder="DD9 7AJ" autocomplete="postal-code" autofocus required>
      <button type="submit">Find addresses</button>
    </form>
    <p class="hint">Stored in a cookie on this device only &mdash; nothing is sent anywhere but Angus Council.</p>
  </div>

<?php elseif ($view === 'pick'): ?>

  <h1>Pick your <strong>address</strong></h1>
  <p class="date-line"><?= count($addresses) ?> found for &ldquo;<?= e($searchTerm) ?>&rdquo;</p>

  <div class="setup">
    <form method="post" action="" id="pickform">
      <label for="uprn">Address</label>
      <select id="uprn" name="uprn" required
              onchange="document.getElementById('label').value = this.options[this.selectedIndex].text">
        <?php foreach ($addresses as $i => $a): ?>
          <option value="<?= e($a['uprn']) ?>"><?= e($a['display']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" id="label" name="label" value="<?= e($addresses[0]['display'] ?? '') ?>">
      <button type="submit">Save this address</button>
    </form>
    <p class="hint"><a href="?change=1">Search a different postcode</a></p>
  </div>

<?php elseif ($error !== null && !$collections): ?>

  <h1>Couldn't reach the <strong>bin data</strong></h1>
  <p class="date-line"><?= e($error) ?></p>
  <p class="date-line"><a href="?change=1">Change address</a></p>

<?php elseif ($nextDate === null): ?>

  <h1>No collections <strong>scheduled</strong></h1>
  <p class="date-line">Nothing returned for <?= $label !== '' ? e($label) : 'UPRN ' . e($uprn) ?>.</p>
  <p class="date-line"><a href="?change=1">Change address</a></p>

<?php else: ?>

  <?php if ($error !== null): ?>
    <p class="notice">Showing cached data &mdash; the council's server didn't respond just now.</p>
  <?php endif; ?>

  <h1>Bins out <strong><?= e($dayMessage) ?></strong></h1>
  <p class="date-line">
    <?= e(date('l j F', strtotime($nextDate))) ?>
    <?php if ($daysAway > 1): ?>
      &middot; <?= (int) $daysAway ?> days away
    <?php endif; ?>
  </p>

  <ul class="bins">
    <?php foreach ($nextBins as $bin): ?>
      <li>
        <figure>
          <span class="bin-tile">
            <img src="<?= e(IMG_DIR . '/' . BINS[$bin]) ?>" alt="<?= e($bin) ?> bin" loading="lazy">
          </span>
          <figcaption><?= e($bin) ?></figcaption>
        </figure>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($upcoming): ?>
    <section class="upcoming">
      <h2>After that</h2>
      <ul>
        <?php foreach ($upcoming as $u): ?>
          <li>
            <span><?= e(date('D j M', strtotime($u['date']))) ?></span>
            <span class="chips">
              <?php foreach ($u['bins'] as $b): ?>
                <span class="chip chip-<?= e(strtolower($b)) ?>" title="<?= e($b) ?>"></span>
              <?php endforeach; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

<?php endif; ?>

  <footer>
    <?php if ($view === 'bins'): ?>
      <?= $label !== '' ? e($label) . ' &middot; ' : '' ?><a href="?change=1">change address</a><br>
    <?php endif; ?>
    Data from Angus Council<?= $fromCache ? ' &middot; cached' : '' ?>
  </footer>
</main>
</body>
</html>
