package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.domain.model.StreetClass
import fr.gshz.hideandseek.domain.model.StreetNetwork
import fr.gshz.hideandseek.domain.model.StreetNetworkStatus
import fr.gshz.hideandseek.domain.model.StreetPoint
import fr.gshz.hideandseek.domain.model.StreetWay
import fr.gshz.hideandseek.domain.repository.StreetNetworkRepository
import kotlinx.coroutines.CompletableDeferred

class FakeStreetNetworkRepository : StreetNetworkRepository {
    var networkResult: Result<StreetNetwork> = Result.success(pending())

    // Holding the fetch open is what makes a network landing mid-draw real rather than hypothetical.
    var fetchGate: CompletableDeferred<Unit>? = null

    data class FetchCall(val roundUuid: String)

    val fetchCalls = mutableListOf<FetchCall>()

    override suspend fun getStreetNetwork(roundUuid: String): StreetNetwork {
        fetchCalls += FetchCall(roundUuid)
        fetchGate?.await()
        return networkResult.getOrThrow()
    }

    companion object {
        fun pending(vararg ways: StreetWay) =
            StreetNetwork(status = StreetNetworkStatus.Pending, ways = ways.toList())

        fun ready(vararg ways: StreetWay) = StreetNetwork(status = StreetNetworkStatus.Ready, ways = ways.toList())

        fun unavailable(vararg ways: StreetWay) =
            StreetNetwork(status = StreetNetworkStatus.Unavailable, ways = ways.toList())

        fun way(
            streetClass: StreetClass = StreetClass.Residential,
            points: List<StreetPoint>,
            junctionIndices: List<Int> = emptyList(),
        ) = StreetWay(streetClass = streetClass, points = points, junctionIndices = junctionIndices)
    }
}
