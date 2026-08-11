package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.domain.model.HidingZone
import fr.gshz.hideandseek.domain.model.ZoneCard
import fr.gshz.hideandseek.domain.model.ZonePlacement
import fr.gshz.hideandseek.domain.repository.ZoneRepository

class FakeZoneRepository : ZoneRepository {
    var setHidingZoneResult: Result<HidingZone>? = null
    var playZoneCardResult: Result<HidingZone>? = null
    var currentZone: HidingZone? = null
    var currentZoneCalls = 0
        private set

    data class SetHidingZoneCall(
        val roundUuid: String,
        val playerUuid: String,
        val lat: Double,
        val lng: Double,
        val radiusMeters: Double?,
        val stationName: String?,
    )

    data class PlayZoneCardCall(
        val roundUuid: String,
        val playerUuid: String,
        val card: ZoneCard,
        val cardPhotoUri: String,
    )

    val calls = mutableListOf<SetHidingZoneCall>()
    val cardCalls = mutableListOf<PlayZoneCardCall>()

    override suspend fun currentHidingZone(roundUuid: String): HidingZone? {
        currentZoneCalls++

        return currentZone
    }

    override suspend fun setHidingZone(
        roundUuid: String,
        playerUuid: String,
        placement: ZonePlacement,
    ): HidingZone {
        calls += SetHidingZoneCall(
            roundUuid,
            playerUuid,
            placement.lat,
            placement.lng,
            placement.radiusMeters,
            placement.stationName,
        )
        return setHidingZoneResult?.getOrThrow() ?: HidingZone(
            roundUuid = roundUuid,
            lat = placement.lat,
            lng = placement.lng,
            radiusMeters = placement.radiusMeters ?: DEFAULT_RADIUS,
            stationName = placement.stationName,
        )
    }

    override suspend fun playZoneCard(
        roundUuid: String,
        playerUuid: String,
        card: ZoneCard,
        cardPhotoUri: String,
    ): HidingZone {
        cardCalls += PlayZoneCardCall(roundUuid, playerUuid, card, cardPhotoUri)
        return playZoneCardResult?.getOrThrow()
            ?: HidingZone(roundUuid = roundUuid, lat = 0.0, lng = 0.0, radiusMeters = DEFAULT_RADIUS)
    }

    private companion object {
        const val DEFAULT_RADIUS = 500.0
    }
}
