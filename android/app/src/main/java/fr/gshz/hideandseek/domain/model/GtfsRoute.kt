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
) {
    val ref: String get() = shortName

    val label: String get() = shortName.ifBlank { longName }

    val displayName: String get() = when {
        shortName.isNotBlank() && longName.isNotBlank() -> "$shortName ($longName)"
        shortName.isNotBlank() -> shortName
        else -> longName
    }

    val routeTypeString: String get() = when (routeType) {
        0 -> "tram"
        1 -> "subway"
        2 -> "train"
        3 -> "bus"
        4 -> "ferry"
        5 -> "cable_tram"
        7 -> "funicular"
        11 -> "trolleybus"
        12 -> "monorail"
        else -> "bus"
    }
}

data class GtfsSourceState(
    val uuid: String,
    val name: String,
    val routes: List<GtfsRoute>,
)
