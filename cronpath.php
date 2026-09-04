<?php
/**
 * cronpath.php - throwaway helper.
 *
 * Upload alongside notify.php, open it in a browser, and it prints the exact cron
 * commands to paste into cPanel with the right paths already filled in.
 *
 * DELETE THIS FILE once you've copied the commands - it exposes server paths.
 */

header('Content-Type: text/plain; charset=utf-8');

$dir = __DIR__;

// cPanel installs PHP in a few well-known places depending on EasyApache setup.
$candidates = [
    '/usr/local/bin/php',
    '/usr/bin/php',
    '/opt/cpanel/ea-php84/root/usr/bin/php',
    '/opt/cpanel/ea-php83/root/usr/bin/php',
    '/opt/cpanel/ea-php82/root/usr/bin/php',
    '/opt/cpanel/ea-php81/root/usr/bin/php',
    '/opt/alt/php84/usr/bin/php',
    '/opt/alt/php83/usr/bin/php',
    '/opt/alt/php82/usr/bin/php',
];

$found = [];
foreach ($candidates as $c) {
    if (@is_executable($c)) {
        $found[] = $c;
    }
}

$php = $found[0] ?? '/usr/local/bin/php';

echo "binday cron setup\n";
echo str_repeat('=', 60), "\n\n";

echo "Script folder : $dir\n";
echo "PHP version   : ", PHP_VERSION, " (", PHP_SAPI, ")\n";
echo "cURL          : ", function_exists('curl_init') ? 'yes' : 'no - will use fallback', "\n";
echo "Folder writable: ", is_writable($dir) ? 'yes' : 'NO - the cache will not save', "\n\n";

echo "PHP CLI binaries found:\n";
if ($found) {
    foreach ($found as $f) {
        echo "  $f\n";
    }
} else {
    echo "  none of the usual paths - check cPanel > Terminal, run: which php\n";
}
echo "\n";

echo "notify.php present: ", is_file("$dir/notify.php") ? 'yes' : 'NO - upload it first', "\n";
echo "angus.php present : ", is_file("$dir/angus.php")  ? 'yes' : 'NO - upload it first', "\n\n";

echo str_repeat('-', 60), "\n";
echo "PASTE THESE INTO cPANEL > CRON JOBS\n";
echo str_repeat('-', 60), "\n\n";

$uprn  = '117060380';
$topic = 'angus-bins-dd97aj';

echo "8:00pm - heads up for tomorrow\n";
echo "  Minute 0  Hour 20  Day *  Month *  Weekday *\n\n";
echo "  $php $dir/notify.php --uprn=$uprn --topic=$topic --quiet\n\n\n";

echo "6:00am - reminder on the day\n";
echo "  Minute 0  Hour 6   Day *  Month *  Weekday *\n\n";
echo "  $php $dir/notify.php --uprn=$uprn --topic=$topic --when=today --quiet\n\n\n";

echo "Test it first (prints instead of sending):\n\n";
echo "  $php $dir/notify.php --uprn=$uprn --topic=$topic --dry-run\n\n";

echo str_repeat('=', 60), "\n";
echo "Now DELETE cronpath.php.\n";
