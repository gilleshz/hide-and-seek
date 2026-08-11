package fr.gshz.hideandseek.domain.model

/**
 * Where the hiding team is settling: the station point, the radius, and the name of the station the
 * hider tapped, read off the transit overlay so Move can name it to the seekers later.
 */
data class ZonePlacement(
    val lat: Double,
    val lng: Double,
    val radiusMeters: Double? = null,
    val stationName: String? = null,
)
