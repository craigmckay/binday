# binday

Unofficial clients for Angus Council bin collection dates — a Python CLI and a
self-hosted PHP web page.

Angus Council doesn't publish an API for bin collections — the only way to get your
dates is the [MyAngus bin collection form](https://myangus.angus.gov.uk/service/Bin_collection_dates_V3),
which is a JavaScript-rendered Granicus/Firmstep AchieveForms page. This project
talks to the endpoints behind that form directly.

No account, API key, or authentication is required. The session token endpoint is public.

## What's here

| File | What it does |
|---|---|
| `index.php` | Responsive web page showing the next collection and its bin colours |
| `notify.php` | Cron job that pushes a reminder to [ntfy](https://ntfy.sh) |
| `angus.php` | Shared API client used by both PHP entry points |
| `angus_bins.py` | Command-line client for looking up addresses and dates |
| `img/bin_*.png` | Bin photos — grey, green, purple, blue, brown |
| `img/bin.png` | Source artwork the icons are generated from |
| `img/ico/` | Home-screen and app icons |
| `favicon.ico` | Browser tab icon, 16–256px |
| `site.webmanifest` | Lets the page install as a standalone app |

---

# Web page (PHP)

A single-file page for a cPanel host, wall display, or spare tablet. Reads live
from the API and caches to a JSON file — no database, no cron job.

![The binday page showing the next collection](img/screenshot.jpg)

### Deploy

Upload the whole folder to your web root — `index.php`, `img/`, `favicon.ico` and
`site.webmanifest`. Requires **PHP 7.0+**; cURL is used if available, with a
stream-wrapper fallback if not.

There's nothing to configure per household — on first visit the page asks for a
postcode, shows the matching addresses, and remembers the chosen UPRN in a cookie
for a year. A `change address` link in the footer starts that over.

```php
const CACHE_TTL   = 6 * 60 * 60;   // how long to trust cached data
const TIMEZONE    = 'Europe/London';
const REFRESH     = 3600;          // browser auto-refresh, 0 to disable
const IMG_DIR     = 'img';
const COOKIE_DAYS = 365;
```

The page writes `.binday-cache-<uprn>.json` next to itself — one file per address,
so a single deployment serves several households. That directory needs to be
writable; if it isn't, everything still works, you just hit the API on every load.

### Behaviour

- First visit asks for a postcode, then an address; the UPRN is stored in a cookie
- Plain form posts, so it works with JavaScript disabled; the API is only ever
  called server-side
- Headline reads `TODAY`, `TOMORROW`, or the weekday name
- Shows every bin due on the next collection date, then the following three dates
  as coloured chips
- Falls back to stale cache if Angus is unreachable, rather than showing an empty page
- Empty API responses are never cached, so a bad response can't wedge the page
- Fluid type and image sizing via `clamp()`; respects `prefers-color-scheme: dark`
- Bin photos sit on light grey tiles — they were shot on white, so this hides the
  antialiased edges instead of retouching the images

### Add to your home screen

`site.webmanifest` and the icons in `img/ico/` make it installable, so it opens
full-screen without browser chrome.

- **iOS** — Safari → Share → *Add to Home Screen*
- **Android** — Chrome → menu → *Install app*

Regenerate the icons from a new `img/bin.png` (512×512, transparent) if you want a
different look. iOS ignores `favicon.ico` and won't render transparency, so
`apple-touch-icon.png` has to be an opaque PNG; iOS rounds the corners itself.
`icon-512-maskable.png` deliberately carries extra margin because Android crops
icons to a circle or squircle.

> iOS caches home-screen icons hard. After changing them, delete the shortcut,
> quit Safari, then re-add — otherwise you'll keep seeing the old one.

---

# Reminders (cron + ntfy)

`notify.php` pushes a reminder to an [ntfy](https://ntfy.sh) topic. Install the ntfy
app, subscribe to your topic, and you get a phone notification the evening before
and again on the morning.

It's silent unless there's a collection on the target day, so most days it sends
nothing at all.

```bash
php notify.php --uprn=117060380 --topic=angus-bins-dd97aj --dry-run
```

```
POST https://ntfy.sh/angus-bins-dd97aj
Title: Bin Reminder
Priority: default
Bins out tomorrow: 🟩 Green & 🟪 Purple
```

### Cron

```cron
# 8pm - heads up for tomorrow
0 20 * * * /usr/local/bin/php /home/USER/public_html/binday/notify.php \
             --uprn=117060380 --topic=angus-bins-dd97aj --quiet

# 6am - reminder on the day itself
0 6 * * *  /usr/local/bin/php /home/USER/public_html/binday/notify.php \
             --uprn=117060380 --topic=angus-bins-dd97aj --when=today --quiet
```

`--quiet` stops cron emailing you on the days it has nothing to say. Check the PHP
binary path in cPanel — it's often `/usr/local/bin/php` or `/opt/cpanel/ea-php82/root/usr/bin/php`.

### Options

| Flag | Description |
|---|---|
| `--uprn=N` | Property UPRN |
| `--topic=NAME` | ntfy topic |
| `--url=URL` | Full ntfy URL instead of `--topic`, for self-hosted ntfy |
| `--when=today` | Target today rather than tomorrow |
| `--dry-run` | Print the message instead of sending it |
| `--quiet` | Say nothing when there's nothing due |

> **ntfy topics are public.** Anyone who guesses the topic name can read your bin
> days and post to it. Use something unguessable, or self-host with auth.

If your host's cron can only fetch a URL rather than run PHP directly, set
`NOTIFY_TOKEN` in `notify.php` to a long random string and call
`notify.php?token=THAT_STRING`. Left empty, web requests return 404.

---

# Command line (Python)

## Install

```bash
pip install requests
```

## Usage

Find your UPRN from a postcode:

```bash
$ python angus_bins.py --postcode "DD9 7AJ"
   117091748  1 PARK GROVE BRECHIN DD9 7AJ
   117060376  2 PARK GROVE BRECHIN DD9 7AJ
   117060380  7 PARK GROVE BRECHIN DD9 7AJ
   ...
```

Then look up collections:

```bash
$ python angus_bins.py --uprn 117060380
2026-08-18  Green    (every 2 weeks)
2026-08-18  Purple   (every 2 weeks)
2026-08-20  Brown    (weekly)
2026-08-25  Grey     (every 4 weeks)
2026-09-08  Blue     (every 4 weeks)
```

Or do both in one go:

```bash
python angus_bins.py --postcode "DD9 7AJ" --house 7
```

### Options

| Flag | Description |
|---|---|
| `--postcode` | Postcode or partial address to search |
| `--house` | House/flat number, auto-picks from the search results |
| `--uprn` | UPRN, skips the address search entirely |
| `--date` | Start date as `YYYY-MM-DD` (default: today) |
| `--json` | Emit raw JSON instead of a table |

Once you know your UPRN, keep it — it never changes. (The web page asks for it
interactively instead, so this only matters for scripting.)

---

# The API

Three calls against `https://myangus.angus.gov.uk`. Everything is a `POST` to
`/apibroker/runLookup` with a lookup ID identifying which query to run.

> **Cookies are mandatory.** The auth call sets a session cookie that the lookups
> require back. Sending the `sid` parameter without it returns `HTTP 200` with an
> empty `rows_data` — success-shaped, but no data. Use a session/cookie jar
> (`requests.Session`, `CURLOPT_COOKIEJAR`) for all three calls.

### 1. Get a session token

```http
GET /authapi/isauthenticated?uri=https://myangus.angus.gov.uk/AchieveForms/
    &hostname=myangus.angus.gov.uk&withCredentials=true
```

```json
{ "auth-session": "b4c366e64c0db72b97e24984c289861a", "is_authenticated": false }
```

Take `auth-session` and pass it as the `sid` query parameter on every subsequent
call — and keep the cookie the response set.

### 2. Search addresses — lookup `65a5507c8d3e6`

```http
POST /apibroker/runLookup?id=65a5507c8d3e6&app_name=AF-Renderer::Self&sid=<sid>
Content-Type: application/json

{"formValues": {"Section 3": {"formatted_search": {"value": "DD9 7AJ"}}}}
```

Returns one row per address in `integration.transformed.rows_data`, keyed by index:

```json
{
  "display":  "7 PARK GROVE BRECHIN DD9 7AJ",
  "UPRN":     "117060380",
  "flat": "7", "house": "", "street": "PARK GROVE",
  "town": "BRECHIN", "postcode": "DD9 7AJ", "locality": "",
  "lacode": "9053", "usrn": "700747",
  "easting": "360604", "northing": "760330",
  "lat": "56.732951254235", "lng": "-2.6455122286955"
}
```

The same data is also returned as `select_data` (`{label, value}` pairs) for
dropdown binding.

### 3. Get collection dates — lookup `66587d491feab`

```http
POST /apibroker/runLookup?id=66587d491feab&app_name=AF-Renderer::Self&sid=<sid>
Content-Type: application/json

{"formValues": {"Section 3": {
    "serviceUPRN":  {"value": "117060380"},
    "currentDate":  {"value": "2026-08-14"}
}}}
```

```json
{
  "binDate": "2026-08-18",
  "binTypeList": "Green",
  "binCollected": " every 2 weeks",
  "greenRoute": "MC_Tue_Eve",
  "greyRoute": "M2_Tue_Eve",
  "purpleRoute": "M2_Tue_Eve",
  "brownRoute": "MF2_Thu",
  "blueRoute": "Tuesday_Odd_1"
}
```

One row per upcoming collection — five bin streams (Green, Purple, Brown, Grey, Blue),
each with its next date and frequency. Green is garden waste and only applies to
subscribers. The `*Route` fields are the council's internal round identifiers and are
the same on every row.

**`currentDate` is required.** Omit it and the API still returns 200 with rows, but the
dates come back as `1900-01-16` style garbage — the SQL does its date arithmetic from a
zero epoch when the parameter is missing. Set it to a future date to look ahead:

```bash
python angus_bins.py --uprn 117060380 --date 2026-12-01
```

### A note on lookup IDs

There's a third lookup, `686cdfffd9945`, which normalises a raw search string into
`formatted_search`. `angus_bins.py` calls it for fidelity with the real form, but a
clean postcode passes through unchanged, so you can skip it.

The IDs are stable but not guaranteed. They live in the form definition, fetchable at:

```
GET /api/get-document/json?uri=<url-encoded sandbox-publish:// uri>&sid=<sid>
```

The response has a base64 blob in `data.content`; decode it to get the form JSON.

## Caveats

- Unofficial and undocumented. Angus Council can change or break any of this without notice.
- Be polite — cache results, don't poll in a loop. One call a day is plenty.
- Collection dates shift around public holidays; the API reflects this, but always
  check the [council's bin pages](https://www.angus.gov.uk/bins_litter_and_recycling)
  if something looks wrong.
- Not affiliated with or endorsed by Angus Council.
