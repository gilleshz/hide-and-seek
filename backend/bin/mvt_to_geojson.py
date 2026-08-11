"""Decode LOOM z14 MVT tiles for one game into a single lon/lat GeoJSON overlay.

Lines and inner-connections become LineStrings carrying their colour/cap/width.
Each station node-front polygon is rebuilt as a smooth stadium (a pill for line
bundles, a circle for single-line termini) so it renders cleanly at any zoom.
Stations carry their label, an id, exact node coordinates, and the list of lines
serving them (ref + colour). When the LOOM line-graph is supplied, coordinates
and line membership are read authoritatively from it (joined by station id);
otherwise they fall back to a 50 m proximity match against the decoded tiles.

Usage: python mvt_to_geojson.py <tiles_dir> <output.geojson> [line-graph.json]
"""
import glob
import json
import math
import os
import sys

import mapbox_vector_tile
from shapely import minimum_rotated_rectangle
from shapely.geometry import Polygon

EXTENT = 1024
ZOOM = 14
EARTH_RADIUS_M = 6378137.0
CAP_SEGMENTS = 16
STATION_LINE_RADIUS_M = 50
LINE_LAYERS = ("lines", "inner-connections")


def tile_to_lonlat(xt, yt, px, py):
    lon = (xt + px / EXTENT) / (2 ** ZOOM) * 360.0 - 180.0
    n = math.pi * (1 - 2 * (yt + py / EXTENT) / (2 ** ZOOM))
    lat = math.degrees(math.atan(math.sinh(n)))
    return lon, lat


def stadium_ring(ring_lonlat):
    lat0 = sum(p[1] for p in ring_lonlat) / len(ring_lonlat)
    lon0 = sum(p[0] for p in ring_lonlat) / len(ring_lonlat)
    mx = math.cos(math.radians(lat0)) * math.pi / 180.0 * EARTH_RADIUS_M
    my = math.pi / 180.0 * EARTH_RADIUS_M

    def to_m(lon, lat):
        return ((lon - lon0) * mx, (lat - lat0) * my)

    def to_lonlat(x, y):
        return (lon0 + x / mx, lat0 + y / my)

    rect = minimum_rotated_rectangle(Polygon([to_m(*p) for p in ring_lonlat]))
    corners = list(rect.exterior.coords)[:4]
    edges = [
        (corners[i], corners[(i + 1) % 4], math.dist(corners[i], corners[(i + 1) % 4]))
        for i in range(4)
    ]
    long_edge = max(edges, key=lambda e: e[2])
    length = long_edge[2]
    radius = min(e[2] for e in edges) / 2.0
    cx = sum(c[0] for c in corners) / 4.0
    cy = sum(c[1] for c in corners) / 4.0
    ax = (long_edge[1][0] - long_edge[0][0]) / max(length, 1e-9)
    ay = (long_edge[1][1] - long_edge[0][1]) / max(length, 1e-9)
    half_straight = max(length / 2.0 - radius, 0.0)

    pts = []
    for end in (1, -1):
        ecx = cx + ax * half_straight * end
        ecy = cy + ay * half_straight * end
        base = math.atan2(ay, ax) + (0 if end == 1 else math.pi)
        for i in range(CAP_SEGMENTS + 1):
            theta = base - math.pi / 2.0 + math.pi * i / CAP_SEGMENTS
            pts.append((ecx + radius * math.cos(theta), ecy + radius * math.sin(theta)))
    ring = [to_lonlat(x, y) for x, y in pts]
    ring.append(ring[0])
    return ring


def haversine_m(lon1, lat1, lon2, lat2):
    dlat = math.radians(lat2 - lat1)
    dlon = math.radians(lon2 - lon1)
    a = math.sin(dlat / 2) ** 2 + math.cos(math.radians(lat1)) * math.cos(
        math.radians(lat2)
    ) * math.sin(dlon / 2) ** 2
    return 2 * EARTH_RADIUS_M * math.asin(math.sqrt(a))


def load_graph(graph_path):
    """Return (coords, lines) keyed by station id from a LOOM line-graph.

    coords[sid] = (lon, lat) from the station's Point node; lines[sid] =
    {label: color} unioned over every edge incident to that node. Authoritative:
    a line serves a station iff an incident edge carries it, so interchanges no
    longer inherit lines that merely pass nearby.
    """
    with open(graph_path, encoding="utf-8") as handle:
        graph = json.load(handle)
    features = graph.get("features", [])
    node_station = {}
    coords = {}
    for feat in features:
        geom = feat.get("geometry") or {}
        props = feat.get("properties") or {}
        if geom.get("type") != "Point":
            continue
        sid = props.get("station_id", "")
        node_id = props.get("id", "")
        if node_id:
            node_station[node_id] = sid
        if sid and sid not in coords:
            lon, lat = geom["coordinates"][:2]
            coords[sid] = (lon, lat)
    lines = {}
    for feat in features:
        geom = feat.get("geometry") or {}
        props = feat.get("properties") or {}
        if geom.get("type") != "LineString":
            continue
        endpoints = {
            node_station.get(props.get("from", "")),
            node_station.get(props.get("to", "")),
        }
        for sid in endpoints:
            if not sid:
                continue
            for line in props.get("lines", []):
                label = line.get("label", "")
                if label:
                    lines.setdefault(sid, {})[label] = line.get("color", "000")
    return coords, lines


def proximity_serving(line_features, stations):
    """Match each line to the stations it passes within STATION_LINE_RADIUS_M.

    Quadratic in lines x stations x vertices, so only worth running when no
    line-graph is available to answer authoritatively.
    """
    serving = {}
    for ref, line_color, coords in (
        (
            f["properties"]["ref"],
            f["properties"]["lineColor"],
            f["geometry"]["coordinates"],
        )
        for f in line_features
        if "ref" in f["properties"]
    ):
        for sid, (_, _, _, cx, cy) in stations.items():
            min_dist = min(
                haversine_m(cx, cy, lon, lat) for lon, lat in coords
            )
            if min_dist < STATION_LINE_RADIUS_M:
                serving.setdefault(sid, {})[ref] = line_color
    return serving


def build(tiles_dir, graph_path=None):
    files = sorted(glob.glob(os.path.join(tiles_dir, "**", "*.mvt"), recursive=True))
    line_features = []
    stations = {}
    for path in files:
        parts = path.split(os.sep)
        xt, yt = int(parts[-2]), int(parts[-1].split(".")[0])
        with open(path, "rb") as handle:
            tile = mapbox_vector_tile.decode(handle.read(), y_coord_down=True)
        for name in LINE_LAYERS:
            layer = tile.get(name)
            if not layer:
                continue
            for feat in layer["features"]:
                geom = feat["geometry"]
                props = feat["properties"]
                is_fill = props.get("lineCap") == "round"
                out = {
                    "color": props.get("color", "000000"),
                    "lineCap": props.get("lineCap", "round"),
                    "width": props.get("width", "1"),
                }
                segments = (
                    [geom["coordinates"]]
                    if geom["type"] == "LineString"
                    else geom["coordinates"]
                )
                for segment in segments:
                    coords = [list(tile_to_lonlat(xt, yt, px, py)) for px, py in segment]
                    feature = {
                        "type": "Feature",
                        "properties": out,
                        "geometry": {"type": "LineString", "coordinates": coords},
                    }
                    if is_fill and props.get("line", ""):
                        feature["properties"]["ref"] = props["line"]
                        feature["properties"]["lineColor"] = props.get(
                            "line-color", out["color"]
                        )
                    line_features.append(feature)
        station_layer = tile.get("stations")
        if station_layer:
            for feat in station_layer["features"]:
                ring = feat["geometry"]["coordinates"][0]
                lonlat = [tile_to_lonlat(xt, yt, px, py) for px, py in ring]
                area = Polygon(lonlat).area
                sid = feat["properties"].get("stationId", "")
                if sid not in stations or area > stations[sid][0]:
                    cx = sum(p[0] for p in lonlat) / len(lonlat)
                    cy = sum(p[1] for p in lonlat) / len(lonlat)
                    stations[sid] = (area, lonlat, feat["properties"], cx, cy)

    graph_coords, graph_lines = {}, {}
    if graph_path and os.path.exists(graph_path):
        try:
            graph_coords, graph_lines = load_graph(graph_path)
        except (OSError, ValueError, KeyError):
            graph_coords, graph_lines = {}, {}
    use_graph = bool(graph_coords) or bool(graph_lines)
    serving = {} if use_graph else proximity_serving(line_features, stations)

    def lines_for(sid):
        pairs = graph_lines.get(sid, {}) if use_graph else serving.get(sid, {})
        return [{"ref": r, "color": c} for r, c in sorted(pairs.items())]

    station_features = []
    for _, lonlat, props, _, _ in stations.values():
        sid = props.get("stationId", "")
        out = {
            "fillColor": props.get("fillColor", "fff"),
            "color": props.get("color", "000"),
            "stationId": sid,
            "stationLabel": props.get("stationLabel", ""),
            "lines": lines_for(sid),
        }
        coord = graph_coords.get(sid)
        if coord:
            out["stationLng"], out["stationLat"] = coord
        station_features.append({
            "type": "Feature",
            "properties": out,
            "geometry": {"type": "Polygon", "coordinates": [stadium_ring(lonlat)]},
        })

    return {"type": "FeatureCollection", "features": line_features + station_features}


if __name__ == "__main__":
    graph_arg = sys.argv[3] if len(sys.argv) > 3 else None
    feature_collection = build(sys.argv[1], graph_arg)
    with open(sys.argv[2], "w", encoding="utf-8") as out:
        json.dump(feature_collection, out)
