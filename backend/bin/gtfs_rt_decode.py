"""Decode a GTFS-Realtime protobuf feed from stdin into the small JSON shape the API uses.

Usage: python gtfs_rt_decode.py < feed.pb

Output:
{"headerTimestamp": 1760000000, "type": "trip_update"|"vehicle"|"none",
 "trips": {"<tripId>": {"startDate": "YYYYMMDD", "delay": 120,
                        "stops": [{"stopId": "...", "seq": 3, "time": 1760000300, "delay": 60}]}}}
"""
import json
import sys

from google.transit import gtfs_realtime_pb2


def stop_event(stop_time_update):
    if stop_time_update.HasField("departure"):
        return stop_time_update.departure
    if stop_time_update.HasField("arrival"):
        return stop_time_update.arrival
    return None


def main():
    feed = gtfs_realtime_pb2.FeedMessage()
    feed.ParseFromString(sys.stdin.buffer.read())

    feed_type = "none"
    trips = {}
    for entity in feed.entity:
        if entity.HasField("trip_update"):
            feed_type = "trip_update"
            update = entity.trip_update
            trip_id = update.trip.trip_id
            if not trip_id:
                continue
            stops = []
            for stop_time_update in update.stop_time_update:
                stop = {"stopId": stop_time_update.stop_id}
                if stop_time_update.HasField("stop_sequence"):
                    stop["seq"] = stop_time_update.stop_sequence
                event = stop_event(stop_time_update)
                if event is not None:
                    if event.HasField("time"):
                        stop["time"] = event.time
                    if event.HasField("delay"):
                        stop["delay"] = event.delay
                stops.append(stop)
            entry = {"stops": stops}
            if update.trip.HasField("start_date"):
                entry["startDate"] = update.trip.start_date
            if update.HasField("delay"):
                entry["delay"] = update.delay
            trips[trip_id] = entry
        elif entity.HasField("vehicle") and feed_type == "none":
            feed_type = "vehicle"

    json.dump(
        {
            "headerTimestamp": feed.header.timestamp if feed.header.HasField("timestamp") else None,
            "type": feed_type,
            "trips": trips,
        },
        sys.stdout,
        separators=(",", ":"),
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
