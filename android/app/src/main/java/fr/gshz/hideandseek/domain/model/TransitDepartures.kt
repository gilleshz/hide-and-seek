package fr.gshz.hideandseek.domain.model

data class TransitDepartures(
    val stationId: String,
    val stationName: String,
    val timezone: String,
    val serverNow: Long,
    val groups: List<TransitDepartureGroup>,
)

data class TransitDepartureGroup(
    val routeId: String,
    val lineRef: String,
    val lineName: String,
    val colour: String,
    val headsign: String,
    val directionId: Int,
    val realtime: Boolean,
    val departures: List<TransitDeparture>,
)

data class TransitDeparture(
    val at: Long,
    val inSeconds: Int,
    val realtime: Boolean,
    val delaySeconds: Int,
    val headsign: String,
)
