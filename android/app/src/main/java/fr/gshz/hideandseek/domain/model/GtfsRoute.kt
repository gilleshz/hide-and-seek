package fr.gshz.hideandseek.domain.model

data class GtfsRoute(
    val sourceUuid: String,
    val sourceName: String,
    val routeId: String,
    val shortName: String,
    val longName: String,
    val routeType: Int,
    val color: String,
    val textColor: String,
    val agencyName: String = "",
) {
    val ref: String get() = shortName

    val label: String get() = shortName.ifBlank { longName }

    val displayName: String get() = when {
        shortName.isNotBlank() && longName.isNotBlank() -> "$shortName ($longName)"
        shortName.isNotBlank() -> shortName
        else -> longName
    }

    val routeTypeString: String get() = when {
        routeType == 2 && agencyName.isNotBlank() -> agencyName
        routeType == 0 -> "tram"
        routeType == 1 -> "subway"
        routeType == 2 -> "train"
        routeType == 3 -> "bus"
        routeType == 4 -> "ferry"
        routeType == 5 -> "cable_tram"
        routeType == 7 -> "funicular"
        routeType == 11 -> "trolleybus"
        routeType == 12 -> "monorail"
        else -> "bus"
    }
}

data class GtfsSourceState(
    val uuid: String,
    val name: String,
    val routes: List<GtfsRoute>,
    val hasRealtime: Boolean = false,
    val requiresSubset: Boolean = false,
    val sizeBytes: Long? = null,
    val uncompressedBytes: Long? = null,
)

data class GtfsRealtimeProbe(
    val status: String,
    val feedType: String? = null,
    val headerAgeSeconds: Int? = null,
    val tripCount: Int = 0,
)
