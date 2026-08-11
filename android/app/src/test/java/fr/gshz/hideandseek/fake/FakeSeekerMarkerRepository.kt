package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.domain.model.SeekerMarker
import fr.gshz.hideandseek.domain.repository.SeekerMarkerRepository

class FakeSeekerMarkerRepository : SeekerMarkerRepository {
    var markers = mutableListOf<SeekerMarker>()
    var nextUuid = "marker-1"

    data class AddCall(val roundUuid: String, val playerUuid: String, val lat: Double, val lng: Double)

    val addCalls = mutableListOf<AddCall>()
    val deleteCalls = mutableListOf<Pair<String, String>>()

    override suspend fun listMarkers(roundUuid: String): List<SeekerMarker> = markers.toList()

    override suspend fun addMarker(
        roundUuid: String,
        playerUuid: String,
        lat: Double,
        lng: Double,
    ): SeekerMarker {
        addCalls += AddCall(roundUuid, playerUuid, lat, lng)
        val marker = SeekerMarker(nextUuid, playerUuid, lat, lng)
        markers += marker
        return marker
    }

    override suspend fun deleteMarker(roundUuid: String, markerUuid: String) {
        deleteCalls += roundUuid to markerUuid
        markers.removeAll { it.uuid == markerUuid }
    }
}
