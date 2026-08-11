package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class LocationPingRequest(
    val playerUuid: String,
    val lat: Double,
    val lng: Double,
    val altitude: Double? = null,
)

@Serializable
data class LocationPingResponse(
    val playerUuid: String,
    val roundUuid: String,
    val recordedAt: String,
    // True only when this very ingest started the round's endgame (seeker pings only).
    val endgame: Boolean? = null,
)

@Serializable
data class LocationEventDto(
    val type: String? = null,
    val playerUuid: String,
    val lat: Double,
    val lng: Double,
    val recordedAt: String,
)
