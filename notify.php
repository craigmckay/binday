<?php
/**
 * notify.php - push a bin reminder to ntfy.
 *
 * Run from cron. Silent unless there's a collection on the target day, so most
 * days it sends nothing.
 *
 *   php notify.php --uprn=117060380 --topic=angus-bins-dd97aj
 *   php notify.php --uprn=117060380 --topic=angus-bins-dd97aj --when=today
 *
 * Options
 *   --uprn=N        Property UPRN. Find it with: angus_bins.py --postcode "DD9 7AJ"
 *   --topic=NAME    ntfy topic. Anyone who knows it can read your bin days, so
 *                   pick something unguessable.
 *   --url=URL       Full ntfy URL, instead of --topic (for self-hosted ntfy)
 *   --when=WHEN     "tomorrow" (default) or "today"
 *   --dry-run       Print the message instead of sending it
 *   --quiet         Suppress the "nothing due" line
 *
 * Exit codes: 0 sent or nothing due, 1 error.
 */

require __DIR__ . '/angus.php';

// ---------------------------------------------------------------- defaults ---

const DEFAULT_UPRN  = '117060380';                 // 7 Park Grove, Brechin DD9 7AJ
const DEFAULT_TOPIC = 'angus-bins-dd97aj';
const NTFY_SERVER   = 'https://ntfy.sh';

// Leave empty to refuse web requests entirely. If your cron can only fetch a URL
// rather than run PHP directly, set a long random string here and call
// notify.php?token=THAT_STRING
const NOTIFY_TOKEN = '';

const BIN_EMOJI = [
    'Grey'   => '⬜',
    'Green'  => '🟩',
    'Purple' => '🟪',
    'Blue'   => '🟦',
    'Brown'  => '🟫',
];

// -------------------------------------------------------------- arguments ---

function arguments()
{
    $args = [];

    if (PHP_SAPI === 'cli') {
        foreach (array_slice($_SERVER['argv'] ?? [], 1) as $a) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $a, $m)) {
                $args[strtolower($m[1])] = $m[2] ?? true;
            }
        }
        return $args;
    }

    // Web request - only allowed when a token is configured and matches.
    if (NOTIFY_TOKEN === '' || ($_GET['token'] ?? '') !== NOTIFY_TOKEN) {
        http_response_code(404);
        exit(1);
    }
    header('Content-Type: text/plain; charset=utf-8');
    foreach (['uprn', 'topic', 'url', 'when'] as $k) {
        if (isset($_GET[$k])) {
            $args[$k] = $_GET[$k];
        }
    }
    foreach (['dry-run', 'quiet'] as $k) {
        if (isset($_GET[$k])) {
            $args[$k] = true;
        }
    }
    return $args;
}

function out($line)
{
    echo $line, PHP_EOL;
}

// ------------------------------------------------------------------- ntfy ---

function ntfy_send($url, $title, $message, $priority, array $tags = [])
{
    $headers = [
        'Title: ' . $title,
        'Priority: ' . $priority,
        'Content-Type: text/plain; charset=utf-8',
    ];
    if ($tags) {
        $headers[] = 'Tags: ' . implode(',', $tags);
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $message,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('curl: ' . $err);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('ntfy returned HTTP ' . $status . ': ' . $body);
        }
        return;
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers) . "\r\n",
        'content'       => $message,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('ntfy request failed');
    }
}

// ------------------------------------------------------------------- main ---

$args = arguments();

$uprn = preg_replace('/\D/', '', (string) ($args['uprn'] ?? DEFAULT_UPRN));
$when = strtolower((string) ($args['when'] ?? 'tomorrow'));
$dry  = isset($args['dry-run']);
$hush = isset($args['quiet']);

if ($uprn === '') {
    fwrite(STDERR, "No UPRN given.\n");
    exit(1);
}
if (!in_array($when, ['today', 'tomorrow'], true)) {
    fwrite(STDERR, "--when must be 'today' or 'tomorrow'.\n");
    exit(1);
}

$url = $args['url'] ?? (NTFY_SERVER . '/' . ($args['topic'] ?? DEFAULT_TOPIC));

$today  = date('Y-m-d');
$target = $when === 'today' ? $today : date('Y-m-d', strtotime('+1 day'));

list($collections, $fromCache, $error) = load_collections($uprn, $today);

if ($error !== null && !$collections) {
    fwrite(STDERR, "Lookup failed: $error\n");
    exit(1);
}

$byDate = collections_by_date($collections);
$bins   = order_bins($byDate[$target] ?? []);

if (!$bins) {
    if (!$hush) {
        out("Nothing due on $target - no notification sent.");
    }
    exit(0);
}

// "🟩 Green & 🟪 Purple"  /  "🟩 Green, 🟪 Purple & 🟫 Brown"
$parts = [];
foreach ($bins as $b) {
    $parts[] = trim((BIN_EMOJI[$b] ?? '') . ' ' . $b);
}
$last = array_pop($parts);
$list = $parts ? implode(', ', $parts) . ' & ' . $last : $last;

if ($when === 'today') {
    $title    = 'Bin Day';
    $message  = 'Bins out today: ' . $list;
    $priority = 'high';
} else {
    $title    = 'Bin Reminder';
    $message  = 'Bins out tomorrow: ' . $list;
    $priority = 'default';
}

if ($dry) {
    out('POST ' . $url);
    out('Title: ' . $title);
    out('Priority: ' . $priority);
    out($message);
    exit(0);
}

try {
    ntfy_send($url, $title, $message, $priority, ['wastebasket']);
} catch (Exception $e) {
    fwrite(STDERR, 'Send failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!$hush) {
    out('Sent: ' . $message);
}
exit(0);
