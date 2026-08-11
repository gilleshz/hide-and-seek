package fr.gshz.hideandseek.domain.repository

data class PossibleAreaData(
    val possibleAreaGeoJson: String?,
    val exclusionGeoJson: String?,
)

interface PossibleAreaRepository {
    suspend fun getPossibleArea(roundUuid: String): PossibleAreaData
}
