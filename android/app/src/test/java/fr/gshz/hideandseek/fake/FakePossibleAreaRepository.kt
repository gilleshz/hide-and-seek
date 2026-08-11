package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.domain.repository.PossibleAreaData
import fr.gshz.hideandseek.domain.repository.PossibleAreaRepository

class FakePossibleAreaRepository : PossibleAreaRepository {
    var getPossibleAreaResult: PossibleAreaData? = PossibleAreaData(null, null)

    var getPossibleAreaCalls = 0

    override suspend fun getPossibleArea(roundUuid: String): PossibleAreaData {
        getPossibleAreaCalls++
        return getPossibleAreaResult ?: PossibleAreaData(null, null)
    }
}
