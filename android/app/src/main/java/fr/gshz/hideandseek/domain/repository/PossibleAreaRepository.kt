package fr.gshz.hideandseek.domain.repository

data class PossibleAreaData(
    val possibleAreaGeoJson: String?,
    val exclusionGeoJson: String?,
    val stationBandGeoJson: String? = null,
    val stationAreaGeoJson: String? = null,
)

interface PossibleAreaRepository {
    suspend fun getPossibleArea(roundUuid: String): PossibleAreaData
}
