package fr.gshz.hideandseek.domain.model

/**
 * OSM street geometry of the round's hiding zone, fetched once and snapped against on device.
 * Hider-only: centring the network on the zone would give a seeker the zone.
 */
data class StreetNetwork(
    val status: StreetNetworkStatus,
    val ways: List<StreetWay> = emptyList(),
)

data class StreetWay(
    val streetClass: StreetClass,
    val points: List<StreetPoint>,
    val junctionIndices: List<Int> = emptyList(),
)

/** Not a ZonePin: that lives in feature/map, and the domain layer must not depend on a feature. */
data class StreetPoint(val latitude: Double, val longitude: Double)

enum class StreetNetworkStatus(val wireValue: String) {
    Pending("pending"),
    Ready("ready"),
    Unavailable("unavailable"),
    ;

    companion object {
        fun fromWireValueOrNull(value: String?): StreetNetworkStatus? = entries.find { it.wireValue == value }
    }
}

/**
 * [snapRank] breaks a near-tie in favour of the more street-like way, so a tap between a road and
 * its mapped sidewalk lands on the road. Lower wins.
 */
enum class StreetClass(val wireValue: String, val snapRank: Int) {
    Motorway("motorway", 0),
    Trunk("trunk", 0),
    Primary("primary", 0),
    Secondary("secondary", 0),
    Tertiary("tertiary", 0),
    Residential("residential", 0),
    Pedestrian("pedestrian", 0),
    Service("service", 1),
    Track("track", 1),
    Path("path", 1),
    Cycleway("cycleway", 1),
    Steps("steps", 1),
    Footway("footway", 1),
    Other("other", 1),
    Sidewalk("sidewalk", 2),
    Crossing("crossing", 2),
    ;

    companion object {
        fun fromWireValueOrNull(value: String?): StreetClass? = entries.find { it.wireValue == value }
    }
}
