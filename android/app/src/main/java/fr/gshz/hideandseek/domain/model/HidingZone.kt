package fr.gshz.hideandseek.domain.model

data class HidingZone(
    val roundUuid: String,
    val lat: Double,
    val lng: Double,
    val radiusMeters: Double,
    // The station the hider designated; Move names it to the seekers.
    val stationName: String? = null,
)
