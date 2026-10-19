"""Build the per-game station timetable index from a merged GTFS feed.

The merged feed was produced by gtfsmerge, so its stop ids are the stable
`S_<lat>_<lon>` ids used by the map overlay. Route, service and trip ids are the
original ones. The original source feeds are read only to map their stop ids to
the merged ids, which is what GTFS-RT needs to join a realtime stop update.

Usage: python gtfs_timetable.py <spec.json>

Spec:
{
  "mergedDir": "/tmp/merged-<uuid>",
  "outputDir": "/app/var/tiles/<uuid>/timetable",
  "daysBack": 2,
  "daysForward": 60,
  "sources": [{"path": "/app/var/uploads/gtfs/<uuid>.zip", "uuid": "...", "selectedRefs": ["L1"]}]
}

Output: meta.json, stops.json, and one <sha1(stationId)>.json per station.
"""
import csv
import hashlib
import io
import json
import math
import os
import sys
import time
import unicodedata
import zipfile
from collections import defaultdict
from datetime import date, datetime, timedelta
from zoneinfo import ZoneInfo

EARTH_RADIUS_M = 6378137.0
MERGE_RADIUS_M = 200.0
GRID_DEGREES = 0.005


def _clean(row):
    return {
        (key.strip() if key else ""): (value.strip() if isinstance(value, str) else "")
        for key, value in row.items()
    }


def iter_csv(path, filename):
    """Yield rows from filename inside a zip or a directory."""
    if os.path.isdir(path):
        target = os.path.join(path, filename)
        if not os.path.isfile(target):
            return
        with open(target, newline="", encoding="utf-8-sig") as handle:
            for row in csv.DictReader(handle):
                yield _clean(row)
        return

    if not os.path.isfile(path):
        return
    with zipfile.ZipFile(path) as archive:
        name = next(
            (entry for entry in archive.namelist() if entry == filename or entry.endswith("/" + filename)),
            None,
        )
        if name is None:
            return
        with archive.open(name) as raw:
            text = io.TextIOWrapper(raw, encoding="utf-8-sig", newline="")
            for row in csv.DictReader(text):
                yield _clean(row)


def parse_time(value):
    parts = value.split(":")
    if len(parts) not in (2, 3):
        return None
    try:
        hours = int(parts[0])
        minutes = int(parts[1])
        seconds = int(parts[2]) if len(parts) == 3 else 0
    except ValueError:
        return None
    if hours < 0 or minutes < 0 or seconds < 0 or minutes > 59 or seconds > 59:
        return None
    return hours * 3600 + minutes * 60 + seconds


def parse_float(value):
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def haversine_m(lat1, lon1, lat2, lon2):
    dlat = math.radians(lat2 - lat1)
    dlon = math.radians(lon2 - lon1)
    a = (
        math.sin(dlat / 2) ** 2
        + math.cos(math.radians(lat1)) * math.cos(math.radians(lat2)) * math.sin(dlon / 2) ** 2
    )
    return 2 * EARTH_RADIUS_M * math.asin(math.sqrt(a))


def normalize_name(value):
    folded = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode()
    return " ".join(folded.lower().split())


def parse_date(value):
    if len(value) != 8 or not value.isdigit():
        return None
    try:
        return date(int(value[0:4]), int(value[4:6]), int(value[6:8]))
    except ValueError:
        return None


def route_ref(route):
    return route.get("route_short_name") or route.get("route_long_name") or route.get("route_id", "")


def route_name(route):
    return route.get("route_long_name") or route.get("route_short_name") or route.get("route_id", "")


def selected_routes(merged_dir, sources):
    """Map the selected refs to merged route ids, attributing each to a source.

    gtfsmerge resolves routes by route_short_name and the first source wins, so
    walking the sources in order mirrors what the merged feed contains.
    """
    routes = {}
    by_ref = defaultdict(list)
    for row in iter_csv(merged_dir, "routes.txt"):
        route_id = row.get("route_id", "")
        if not route_id:
            continue
        routes[route_id] = row
        by_ref[route_ref(row)].append(route_id)
        long_name = row.get("route_long_name", "")
        if long_name and long_name != route_ref(row):
            by_ref[long_name].append(route_id)

    selected = {}
    for source_index, source in enumerate(sources):
        for ref in source.get("selectedRefs", []):
            for route_id in by_ref.get(ref, []):
                selected.setdefault(route_id, source_index)
    return routes, selected


def selected_trips(merged_dir, selected):
    trips = {}
    for row in iter_csv(merged_dir, "trips.txt"):
        route_id = row.get("route_id", "")
        trip_id = row.get("trip_id", "")
        if not trip_id or route_id not in selected:
            continue
        direction = row.get("direction_id", "")
        trips[trip_id] = {
            "route": route_id,
            "service": row.get("service_id", ""),
            "headsign": row.get("trip_headsign", ""),
            "direction": int(direction) if direction.isdigit() else 0,
            "src": selected[route_id],
        }
    return trips


def station_departures(merged_dir, trips):
    """Read stop_times once, keeping raw GTFS seconds and dropping each trip's terminus."""
    max_seq = {}
    by_stop = defaultdict(list)
    for row in iter_csv(merged_dir, "stop_times.txt"):
        trip_id = row.get("trip_id", "")
        trip = trips.get(trip_id)
        if trip is None:
            continue
        stop_id = row.get("stop_id", "")
        raw_seq = row.get("stop_sequence", "")
        if not stop_id or not raw_seq.isdigit():
            continue
        seconds = parse_time(row.get("departure_time", "")) or parse_time(row.get("arrival_time", ""))
        if seconds is None:
            continue
        sequence = int(raw_seq)
        max_seq[trip_id] = max(max_seq.get(trip_id, 0), sequence)
        by_stop[stop_id].append(
            {
                "route": trip["route"],
                "service": trip["service"],
                "seconds": seconds,
                "trip": trip_id,
                "direction": trip["direction"],
                "headsign": trip["headsign"],
                "seq": sequence,
                "src": trip["src"],
            }
        )

    return {
        stop_id: [dep for dep in departures if dep["seq"] != max_seq[dep["trip"]]]
        for stop_id, departures in by_stop.items()
    }


def merged_stops(merged_dir):
    stops = {}
    for row in iter_csv(merged_dir, "stops.txt"):
        stop_id = row.get("stop_id", "")
        lat = parse_float(row.get("stop_lat", ""))
        lon = parse_float(row.get("stop_lon", ""))
        if stop_id and lat is not None and lon is not None:
            stops[stop_id] = {"name": row.get("stop_name", ""), "lat": lat, "lon": lon}
    return stops


def original_stops_by_merged(stops, sources):
    """Map every original stop id to the merged stop it belongs to.

    The exact `S_<lat>_<lon>` id covers most stops. gtfsmerge can pick another
    member of a merge group as the representative, so the fallback is the nearest
    same-name merged stop inside the 200 m merge radius.
    """
    grid = defaultdict(list)
    for stop_id, info in stops.items():
        grid[(math.floor(info["lat"] / GRID_DEGREES), math.floor(info["lon"] / GRID_DEGREES))].append(stop_id)

    mapped = defaultdict(set)
    for source_index, source in enumerate(sources):
        for row in iter_csv(source["path"], "stops.txt"):
            stop_id = row.get("stop_id", "")
            lat = parse_float(row.get("stop_lat", ""))
            lon = parse_float(row.get("stop_lon", ""))
            if not stop_id or lat is None or lon is None:
                continue
            exact = "S_%.4f_%.4f" % (lat, lon)
            if exact in stops:
                mapped[exact].add((source_index, stop_id))
                continue

            name = normalize_name(row.get("stop_name", ""))
            cell = (math.floor(lat / GRID_DEGREES), math.floor(lon / GRID_DEGREES))
            nearest = None
            nearest_distance = MERGE_RADIUS_M
            for dy in (-1, 0, 1):
                for dx in (-1, 0, 1):
                    for merged_id in grid.get((cell[0] + dy, cell[1] + dx), []):
                        info = stops[merged_id]
                        if name and normalize_name(info["name"]) != name:
                            continue
                        distance = haversine_m(lat, lon, info["lat"], info["lon"])
                        if distance <= nearest_distance:
                            nearest = merged_id
                            nearest_distance = distance
            if nearest is not None:
                mapped[nearest].add((source_index, stop_id))

    return {stop_id: sorted(members) for stop_id, members in mapped.items()}


def service_days(merged_dir, used_services, days_back, days_forward, timezone_name):
    weekly = {}
    for row in iter_csv(merged_dir, "calendar.txt"):
        service = row.get("service_id", "")
        start = parse_date(row.get("start_date", ""))
        end = parse_date(row.get("end_date", ""))
        if not service or start is None or end is None:
            continue
        flags = [
            row.get("monday") == "1",
            row.get("tuesday") == "1",
            row.get("wednesday") == "1",
            row.get("thursday") == "1",
            row.get("friday") == "1",
            row.get("saturday") == "1",
            row.get("sunday") == "1",
        ]
        weekly[service] = (flags, start, end)

    exceptions = defaultdict(dict)
    for row in iter_csv(merged_dir, "calendar_dates.txt"):
        service = row.get("service_id", "")
        day = parse_date(row.get("date", ""))
        exception = row.get("exception_type", "")
        if service and day is not None and exception in ("1", "2"):
            exceptions[day][service] = int(exception)

    try:
        today = datetime.now(ZoneInfo(timezone_name)).date()
    except Exception:
        today = datetime.now(ZoneInfo("UTC")).date()

    active_days = {}
    for offset in range(-days_back, days_forward + 1):
        day = today + timedelta(days=offset)
        active = {
            service
            for service, (flags, start, end) in weekly.items()
            if service in used_services and start <= day <= end and flags[day.weekday()]
        }
        for service, exception in exceptions.get(day, {}).items():
            if service not in used_services:
                continue
            if exception == 1:
                active.add(service)
            else:
                active.discard(service)
        if active:
            active_days[day.isoformat()] = sorted(active)
    return active_days


def build(spec):
    merged_dir = spec["mergedDir"]
    output_dir = spec["outputDir"]
    sources = spec.get("sources", [])
    days_back = int(spec.get("daysBack", 2))
    days_forward = int(spec.get("daysForward", 60))

    routes, selected = selected_routes(merged_dir, sources)
    trips = selected_trips(merged_dir, selected)
    departures = station_departures(merged_dir, trips)
    stops = merged_stops(merged_dir)
    original_by_merged = original_stops_by_merged(stops, sources)

    timezone_name = "UTC"
    for row in iter_csv(merged_dir, "agency.txt"):
        if row.get("agency_timezone"):
            timezone_name = row["agency_timezone"]
            break

    used_services = {trip["service"] for trip in trips.values()}
    days = service_days(merged_dir, used_services, days_back, days_forward, timezone_name)

    os.makedirs(output_dir, exist_ok=True)
    stops_out = {}
    station_count = 0
    departure_count = 0
    for station_id, rows in departures.items():
        if not rows:
            continue
        rows.sort(key=lambda row: row["seconds"])
        filename = hashlib.sha1(station_id.encode("utf-8")).hexdigest() + ".json"
        with open(os.path.join(output_dir, filename), "w", encoding="utf-8") as handle:
            json.dump(rows, handle, separators=(",", ":"), ensure_ascii=False)
        original = original_by_merged.get(station_id, [])
        stops_out[station_id] = [{"src": src, "stopId": stop_id} for src, stop_id in original]
        station_count += 1
        departure_count += len(rows)

    with open(os.path.join(output_dir, "stops.json"), "w", encoding="utf-8") as handle:
        json.dump(stops_out, handle, separators=(",", ":"), ensure_ascii=False)

    meta = {
        "generatedAt": int(time.time()),
        "timezone": timezone_name,
        "sources": [{"uuid": source.get("uuid", "")} for source in sources],
        "routes": {
            route_id: {
                "ref": route_ref(routes[route_id]),
                "name": route_name(routes[route_id]),
                "colour": routes[route_id].get("route_color", ""),
            }
            for route_id in selected
            if route_id in routes
        },
        "serviceDays": days,
    }
    with open(os.path.join(output_dir, "meta.json"), "w", encoding="utf-8") as handle:
        json.dump(meta, handle, separators=(",", ":"), ensure_ascii=False)

    print(
        f"gtfs_timetable: {len(selected)} route(s), {station_count} station(s), "
        f"{departure_count} departure(s), {len(days)} service day(s)",
        file=sys.stderr,
    )


def main():
    if len(sys.argv) != 2:
        print("usage: gtfs_timetable.py <spec.json>", file=sys.stderr)
        return 2
    with open(sys.argv[1], encoding="utf-8") as handle:
        spec = json.load(handle)
    build(spec)
    return 0


if __name__ == "__main__":
    sys.exit(main())
