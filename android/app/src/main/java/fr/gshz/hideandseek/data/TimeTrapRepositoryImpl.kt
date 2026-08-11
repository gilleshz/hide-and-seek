package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.mapper.toDomain
import fr.gshz.hideandseek.data.remote.ImageParts
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.TimeTrapResolutionRequest
import fr.gshz.hideandseek.domain.model.TimeTrap
import fr.gshz.hideandseek.domain.repository.TimeTrapRepository
import javax.inject.Inject

class TimeTrapRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
    private val imageParts: ImageParts,
) : TimeTrapRepository {

    override suspend fun listTimeTraps(roundUuid: String): List<TimeTrap> =
        api.getTimeTraps(urlFor("/api/rounds/$roundUuid/time-traps")).map { it.toDomain() }

    override suspend fun placeTimeTrap(
        roundUuid: String,
        playerUuid: String,
        lat: Double,
        lng: Double,
        cardPhotoUri: String,
    ): TimeTrap = api.placeTimeTrap(
        url = urlFor("/api/rounds/$roundUuid/time-traps"),
        image = imageParts.image(cardPhotoUri),
        playerUuid = imageParts.text(playerUuid),
        lat = imageParts.text(lat.toString()),
        lng = imageParts.text(lng.toString()),
    ).toDomain()

    override suspend fun resolveTimeTrap(
        roundUuid: String,
        trapUuid: String,
        playerUuid: String,
        confirmed: Boolean,
    ): TimeTrap = api.resolveTimeTrap(
        url = urlFor("/api/rounds/$roundUuid/time-traps/$trapUuid/resolution"),
        body = TimeTrapResolutionRequest(playerUuid = playerUuid, confirmed = confirmed),
    ).toDomain()

    private suspend fun urlFor(path: String): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/') + path
}
