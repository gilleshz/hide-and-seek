package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.mapper.toDomain
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.AddSeekerCandidateMarkerRequest
import fr.gshz.hideandseek.domain.model.SeekerMarker
import fr.gshz.hideandseek.domain.repository.SeekerMarkerRepository
import javax.inject.Inject

class SeekerMarkerRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
) : SeekerMarkerRepository {

    override suspend fun listMarkers(roundUuid: String): List<SeekerMarker> =
        api.getSeekerCandidateMarkers(urlFor("/api/rounds/$roundUuid/seeker-candidate-markers"))
            .map { it.toDomain() }

    override suspend fun addMarker(
        roundUuid: String,
        playerUuid: String,
        lat: Double,
        lng: Double,
    ): SeekerMarker = api.addSeekerCandidateMarker(
        url = urlFor("/api/rounds/$roundUuid/seeker-candidate-markers"),
        body = AddSeekerCandidateMarkerRequest(playerUuid = playerUuid, lat = lat, lng = lng),
    ).toDomain()

    override suspend fun deleteMarker(roundUuid: String, markerUuid: String) {
        api.deleteSeekerCandidateMarker(
            urlFor("/api/rounds/$roundUuid/seeker-candidate-markers/$markerUuid"),
        )
    }

    private suspend fun urlFor(path: String): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/') + path
}
