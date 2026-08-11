#!/usr/bin/env python3
"""Drop line-graph edges that contain an implausibly long straight segment.

topo can concatenate two disconnected fragments of a route into a single edge,
leaving a straight jump between them: a Swiss rail selection yields 16 such
segments, the worst 41.7 km of invented track next to the real alignment. Real
geometry never needs a jump that long, because the graph feeding topo tops out
around 15 km on the Gotthard base tunnel, so the two cases separate cleanly on
length. Dropping the whole edge costs only the short genuine fragments at its
ends and needs no node surgery, unlike splitting it.

Usage: drop_graph_jumps.py [max_segment_metres] < graph.json > cleaned.json
"""

import json
import math
import sys

DEFAULT_MAX_SEGMENT_M = 20000.0
EARTH_RADIUS_M = 6371000.0


def metres(a, b):
    lon_a, lat_a = a[0], a[1]
    lon_b, lat_b = b[0], b[1]
    mid = math.radians((lat_a + lat_b) / 2.0)
    dx = math.radians(lon_b - lon_a) * EARTH_RADIUS_M * math.cos(mid)
    dy = math.radians(lat_b - lat_a) * EARTH_RADIUS_M
    return math.hypot(dx, dy)


def longest_segment(coords):
    if len(coords) < 2:
        return 0.0
    return max(metres(a, b) for a, b in zip(coords, coords[1:]))


def clean(graph, max_segment_m):
    features = graph.get("features") or []
    kept = []
    dropped = 0
    dropped_metres = 0.0

    for feature in features:
        geometry = feature.get("geometry") or {}
        if geometry.get("type") != "LineString":
            kept.append(feature)
            continue
        worst = longest_segment(geometry.get("coordinates") or [])
        if worst > max_segment_m:
            dropped += 1
            dropped_metres += worst
            continue
        kept.append(feature)

    degree = {}
    for feature in kept:
        if (feature.get("geometry") or {}).get("type") != "LineString":
            continue
        props = feature.get("properties") or {}
        for key in ("from", "to"):
            node = props.get(key)
            if node is not None:
                degree[node] = degree.get(node, 0) + 1

    result = []
    orphans = 0
    for feature in kept:
        geometry = feature.get("geometry") or {}
        if geometry.get("type") != "Point":
            result.append(feature)
            continue
        props = feature.get("properties") or {}
        deg = degree.get(props.get("id"), 0)
        if deg == 0:
            orphans += 1
            continue
        props["deg"] = str(deg)
        result.append(feature)

    graph["features"] = result
    return graph, dropped, dropped_metres, orphans


def main():
    max_segment_m = DEFAULT_MAX_SEGMENT_M
    if len(sys.argv) > 1:
        max_segment_m = float(sys.argv[1])

    graph = json.load(sys.stdin)
    graph, dropped, dropped_metres, orphans = clean(graph, max_segment_m)
    json.dump(graph, sys.stdout)

    if dropped:
        sys.stderr.write(
            f"drop_graph_jumps: removed {dropped} edge(s) carrying "
            f"{dropped_metres / 1000:.1f} km of straight jumps, "
            f"{orphans} node(s) left unconnected\n"
        )


if __name__ == "__main__":
    main()
