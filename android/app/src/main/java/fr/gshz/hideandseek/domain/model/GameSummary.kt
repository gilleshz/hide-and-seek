package fr.gshz.hideandseek.domain.model

data class GameSummary(
    val uuid: String,
    val name: String,
    val size: GameSize,
    val edition: Edition,
    val roundUuid: String,
    val boundarySet: Boolean = false,
    val boundarySwLat: Double? = null,
    val boundarySwLng: Double? = null,
    val boundaryNeLat: Double? = null,
    val boundaryNeLng: Double? = null,
    val transitTilesPath: String? = null,
    val boundaryGeoJson: String? = null,
    val joinCode: String? = null,
    val defaultHidingPeriodMinutes: Int? = null,
    val selectedTransitLines: List<TransitLine> = emptyList(),
)
