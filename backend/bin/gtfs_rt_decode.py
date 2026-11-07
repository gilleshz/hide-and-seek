"""Decode a GTFS-Realtime protobuf feed from stdin into the small JSON shape the API uses.

Usage: python gtfs_rt_decode.py < feed.pb

Output:
{"headerTimestamp": 1760000000, "type": "trip_update"|"vehicle"|"alert"|"none",
 "trips": {"<tripId>": {"startDate": "YYYYMMDD", "delay": 120,
                        "stops": [{"stopId": "...", "seq": 3, "time": 1760000300, "delay": 60}]}},
 "alerts": [{"id": "...", "cause": "strike", "effect": "no_service",
             "headerText": [{"lang": "fr", "value": "..."}],
             "descriptionText": [{"lang": "fr", "value": "..."}],
             "routeIds": ["..."], "stopIds": ["..."],
             "validFrom": 1760000000, "validUntil": null}]}
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


def translations(translated_string):
    return [{"lang": t.language, "value": t.text} for t in translated_string.translation]


def alert_entry(entity):
    alert = entity.alert
    route_ids = []
    stop_ids = []
    for informed in alert.informed_entity:
        if informed.route_id:
            route_ids.append(informed.route_id)
        if informed.stop_id:
            stop_ids.append(informed.stop_id)
    valid_from = None
    valid_until = None
    if alert.active_period:
        period = alert.active_period[0]
        if period.HasField("start"):
            valid_from = period.start
        if period.HasField("end"):
            valid_until = period.end
    return {
        "id": entity.id,
        "cause": gtfs_realtime_pb2.Alert.Cause.Name(alert.cause).lower(),
        "effect": gtfs_realtime_pb2.Alert.Effect.Name(alert.effect).lower(),
        "headerText": translations(alert.header_text),
        "descriptionText": translations(alert.description_text),
        "routeIds": route_ids,
        "stopIds": stop_ids,
        "validFrom": valid_from,
        "validUntil": valid_until,
    }


def main():
    feed = gtfs_realtime_pb2.FeedMessage()
    feed.ParseFromString(sys.stdin.buffer.read())

    feed_type = "none"
    trips = {}
    alerts = []
    for entity in feed.entity:
        if entity.HasField("alert"):
            if feed_type == "none":
                feed_type = "alert"
            alerts.append(alert_entry(entity))
        elif entity.HasField("trip_update"):
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
            "alerts": alerts,
        },
        sys.stdout,
        separators=(",", ":"),
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
