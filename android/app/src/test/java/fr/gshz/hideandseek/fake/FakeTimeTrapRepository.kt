package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.domain.model.TimeTrap
import fr.gshz.hideandseek.domain.model.TimeTrapStatus
import fr.gshz.hideandseek.domain.repository.TimeTrapRepository

class FakeTimeTrapRepository : TimeTrapRepository {
    var listResult: Result<List<TimeTrap>> = Result.success(emptyList())
    var placeResult: Result<TimeTrap>? = null
    var resolveResult: Result<TimeTrap>? = null

    data class PlaceCall(
        val roundUuid: String,
        val playerUuid: String,
        val lat: Double,
        val lng: Double,
        val cardPhotoUri: String,
    )

    data class ResolveCall(
        val roundUuid: String,
        val trapUuid: String,
        val playerUuid: String,
        val confirmed: Boolean,
    )

    val placeCalls = mutableListOf<PlaceCall>()
    val resolveCalls = mutableListOf<ResolveCall>()

    override suspend fun listTimeTraps(roundUuid: String): List<TimeTrap> = listResult.getOrThrow()

    override suspend fun placeTimeTrap(
        roundUuid: String,
        playerUuid: String,
        lat: Double,
        lng: Double,
        cardPhotoUri: String,
    ): TimeTrap {
        placeCalls += PlaceCall(roundUuid, playerUuid, lat, lng, cardPhotoUri)
        return placeResult?.getOrThrow() ?: trap(uuid = "trap-placed", lat = lat, lng = lng)
    }

    override suspend fun resolveTimeTrap(
        roundUuid: String,
        trapUuid: String,
        playerUuid: String,
        confirmed: Boolean,
    ): TimeTrap {
        resolveCalls += ResolveCall(roundUuid, trapUuid, playerUuid, confirmed)
        return resolveResult?.getOrThrow() ?: trap(
            uuid = trapUuid,
            status = if (confirmed) TimeTrapStatus.Sprung else TimeTrapStatus.Armed,
        )
    }

    companion object {
        fun trap(
            uuid: String = "trap-1",
            status: TimeTrapStatus = TimeTrapStatus.Armed,
            lat: Double = 46.52,
            lng: Double = 6.63,
            placedAtEpochMs: Long = 0L,
        ) = TimeTrap(
            uuid = uuid,
            roundUuid = "round-1",
            stationName = "Flon",
            lat = lat,
            lng = lng,
            placedAtEpochMs = placedAtEpochMs,
            status = status,
            valueSeconds = 0,
            intervalMinutes = 15,
            incrementMinutes = 4,
        )
    }
}
