<?php
/**
 * angus.php - shared client for the MyAngus bin collection API.
 *
 * Included by index.php (the web page) and notify.php (the cron job). Holds
 * everything that talks to Angus Council so there's one place to fix when they
 * change something.
 *
 * Needs PHP 7.0+. Uses cURL when available, with a stream-wrapper fallback.
 */

// Not a page - refuse to serve it directly if someone browses to it.
if (PHP_SAPI !== 'cli'
    && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

// ---------------------------------------------------------------- config ---

const CACHE_TTL = 6 * 60 * 60;           // seconds - 6 hours
const TIMEZONE  = 'Europe/London';

const HOST = 'https://myangus.angus.gov.uk';
const LOOKUP_COLLECTIONS   = '66587d491feab';   // UPRN + date -> collection dates
const LOOKUP_ADDRESSES     = '65a5507c8d3e6';   // postcode    -> addresses + UPRN
const LOOKUP_FORMAT_SEARCH = '686cdfffd9945';   // raw search  -> normalised search

// Every stream the API can return, in the order we like to show them.
const BIN_ORDER = ['Grey', 'Green', 'Purple', 'Blue', 'Brown'];

const USER_AGENT = 'Mozilla/5.0 (compatible; binday/1.0)';

date_default_timezone_set(TIMEZONE);

// ------------------------------------------------------------ http helper ---
//
// IMPORTANT: the API hands out a session cookie on the auth call and *requires*
// it back on the lookup. Passing the sid query parameter alone returns HTTP 200
// with an empty result set - which looks like "no collections" rather than an
// error. So every request below shares one cookie jar.

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

function http_get($url)           { return http_request($url); }
function http_post_json($url, $b) { return http_request($url, $b); }

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
 * UPRN + start date -> collections.
 * @return array list of ['date' => 'Y-m-d', 'bin' => 'Green', 'frequency' => '...']
 */
function angus_collections($uprn, $fromDate)
{
    $rows = angus_lookup(LOOKUP_COLLECTIONS, [
        'serviceUPRN' => (string) $uprn,
        'currentDate' => $fromDate,
    ]);

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

/**
 * @return array [collections, fromCache, errorOrNull]
 */
function load_collections($uprn, $today)
{
    $file   = cache_file($uprn);
    $cached = null;

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

// ----------------------------------------------------------------- helpers ---

/** Group collections into ['Y-m-d' => ['Green', 'Purple']], date order. */
function collections_by_date(array $collections)
{
    $byDate = [];
    foreach ($collections as $c) {
        $byDate[$c['date']][] = $c['bin'];
    }
    ksort($byDate);
    return $byDate;
}

/** Put a set of bin names into BIN_ORDER, dropping anything unrecognised. */
function order_bins(array $bins)
{
    return array_values(array_filter(BIN_ORDER, function ($b) use ($bins) {
        return in_array($b, $bins, true);
    }));
}
