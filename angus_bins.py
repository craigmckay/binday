#!/usr/bin/env python3
"""
Angus Council bin collection dates - unofficial API client.

Reverse-engineered from the MyAngus "Bin collection dates" form
(Granicus / Firmstep AchieveForms):
https://myangus.angus.gov.uk/service/Bin_collection_dates_V3

No authentication or account required - the session token endpoint is public.

Usage:
    python angus_bins.py --postcode "DD9 7AJ"          # list addresses + UPRNs
    python angus_bins.py --uprn 117060380              # collections from today
    python angus_bins.py --uprn 117060380 --date 2026-09-01
    python angus_bins.py --postcode "DD9 7AJ" --house 7   # resolve + fetch in one go
    python angus_bins.py --uprn 117060380 --json
"""

import argparse
import json
import sys
import time
from datetime import date

import requests

HOST = "https://myangus.angus.gov.uk"

# Lookup IDs lifted from the live form definition.
LOOKUP_FORMAT_SEARCH = "686cdfffd9945"  # search string -> formatted_search
LOOKUP_ADDRESS_SEARCH = "65a5507c8d3e6"  # formatted_search -> address list (with UPRN)
LOOKUP_COLLECTIONS = "66587d491feab"  # serviceUPRN + currentDate -> collection dates

SECTION = "Section 3"  # the form section all field names live under


def get_session(s: requests.Session) -> str:
    """Fetch a public API session token (sid). No login needed."""
    r = s.get(
        f"{HOST}/authapi/isauthenticated",
        params={
            "uri": f"{HOST}/AchieveForms/",
            "hostname": "myangus.angus.gov.uk",
            "withCredentials": "true",
        },
        timeout=30,
    )
    r.raise_for_status()
    return r.json()["auth-session"]


def run_lookup(s: requests.Session, sid: str, lookup_id: str, fields: dict) -> dict:
    """POST a form lookup. `fields` is {field_name: value}."""
    form_values = {k: {"value": v} for k, v in fields.items()}
    r = s.post(
        f"{HOST}/apibroker/runLookup",
        params={
            "id": lookup_id,
            "repeat_against": "",
            "noRetry": "true",
            "getOnlyTokens": "undefined",
            "log_id": "",
            "app_name": "AF-Renderer::Self",
            "_": str(int(time.time() * 1000)),
            "sid": sid,
        },
        headers={
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
        },
        json={"formValues": {SECTION: form_values}},
        timeout=30,
    )
    r.raise_for_status()
    payload = r.json()
    transformed = payload.get("integration", {}).get("transformed", {})
    if transformed.get("error"):
        raise RuntimeError(f"lookup {lookup_id} failed: {transformed['error']}")
    rows = transformed.get("rows_data") or {}
    if not isinstance(rows, dict):  # empty result comes back as []
        rows = {}
    return rows


def search_addresses(s: requests.Session, sid: str, query: str) -> list:
    """Postcode or partial address -> list of address dicts (incl. UPRN)."""
    # The form normalises the search string first; harmless but we mirror it.
    fmt = run_lookup(s, sid, LOOKUP_FORMAT_SEARCH, {"search": query})
    formatted = next(iter(fmt.values()), {}).get("formatted_search", query)
    rows = run_lookup(s, sid, LOOKUP_ADDRESS_SEARCH, {"formatted_search": formatted})
    return list(rows.values())


def get_collections(s: requests.Session, sid: str, uprn: str, from_date: str) -> list:
    """UPRN + ISO date -> list of upcoming collections."""
    rows = run_lookup(
        s, sid, LOOKUP_COLLECTIONS,
        {"serviceUPRN": str(uprn), "currentDate": from_date},
    )
    return [
        {
            "date": r.get("binDate"),
            "bin": r.get("binTypeList"),
            "frequency": (r.get("binCollected") or "").strip(),
        }
        for r in rows.values()
    ]


def main() -> int:
    p = argparse.ArgumentParser(description="Angus Council bin collection dates")
    p.add_argument("--postcode", help="postcode or partial address to search")
    p.add_argument("--house", help="house/flat number to auto-pick from search results")
    p.add_argument("--uprn", help="UPRN (skips the address search)")
    p.add_argument("--date", default=date.today().isoformat(),
                   help="start date, YYYY-MM-DD (default: today)")
    p.add_argument("--json", action="store_true", help="output raw JSON")
    args = p.parse_args()

    if not args.postcode and not args.uprn:
        p.error("give --postcode and/or --uprn")

    s = requests.Session()
    s.headers["User-Agent"] = "Mozilla/5.0"
    sid = get_session(s)

    uprn = args.uprn
    if not uprn:
        addresses = search_addresses(s, sid, args.postcode)
        if not addresses:
            print(f"No addresses found for {args.postcode!r}", file=sys.stderr)
            return 1
        if args.house:
            match = next(
                (a for a in addresses
                 if a["display"].split()[0].lower() == args.house.lower()), None)
            if not match:
                print(f"No address starting {args.house!r} in results", file=sys.stderr)
                return 1
            uprn = match["UPRN"]
        else:
            if args.json:
                print(json.dumps(addresses, indent=2))
            else:
                for a in addresses:
                    print(f"{a['UPRN']:>12}  {a['display']}")
            return 0

    collections = get_collections(s, sid, uprn, args.date)
    if args.json:
        print(json.dumps(collections, indent=2))
    else:
        if not collections:
            print("No collections returned (check the UPRN).")
        for c in collections:
            print(f"{c['date']}  {c['bin']:<8} ({c['frequency']})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
