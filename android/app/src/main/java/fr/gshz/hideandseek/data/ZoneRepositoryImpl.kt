package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.mapper.toDomain
import fr.gshz.hideandseek.data.remote.ImageParts
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.SetHidingZoneRequest
import fr.gshz.hideandseek.domain.model.HidingZone
import fr.gshz.hideandseek.domain.model.ZoneCard
import fr.gshz.hideandseek.domain.model.ZonePlacement
import fr.gshz.hideandseek.domain.repository.ZoneRepository
import java.net.HttpURLConnection
import javax.inject.Inject
import retrofit2.HttpException

class ZoneRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
    private val imageParts: ImageParts,
) : ZoneRepository {

    override suspend fun currentHidingZone(roundUuid: String): HidingZone? = try {
        api.getHidingZone(
            url = urlFor("/api/rounds/$roundUuid/zone"),
        ).toDomain()
    } catch (e: HttpException) {
        if (e.code() == HttpURLConnection.HTTP_NOT_FOUND) null else throw e
    }

    override suspend fun setHidingZone(
        roundUuid: String,
        playerUuid: String,
        placement: ZonePlacement,
    ): HidingZone = api.setHidingZone(
        url = urlFor("/api/rounds/$roundUuid/zone"),
        body = SetHidingZoneRequest(
            playerUuid = playerUuid,
            lat = placement.lat,
            lng = placement.lng,
            radiusMeters = placement.radiusMeters,
            stationName = placement.stationName,
        ),
    ).toDomain()

    override suspend fun playZoneCard(
        roundUuid: String,
        playerUuid: String,
        card: ZoneCard,
        cardPhotoUri: String,
    ): HidingZone = api.playZoneCard(
        url = urlFor("/api/rounds/$roundUuid/zone/card"),
        image = imageParts.image(cardPhotoUri),
        playerUuid = imageParts.text(playerUuid),
        card = imageParts.text(card.wireValue),
    ).toDomain()

    private suspend fun urlFor(path: String): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/') + path
}
