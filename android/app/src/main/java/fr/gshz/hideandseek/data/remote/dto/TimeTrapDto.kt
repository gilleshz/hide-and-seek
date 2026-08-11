package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class TimeTrapDto(
    val uuid: String = "",
    val roundUuid: String = "",
    val stationName: String? = null,
    val lat: Double = 0.0,
    val lng: Double = 0.0,
    val placedAt: String? = null,
    val status: String = "armed",
    val valueSeconds: Int = 0,
    val intervalMinutes: Int = 0,
    val incrementMinutes: Int = 0,
    val detectedAt: String? = null,
    val detectedByName: String? = null,
    val awardedSeconds: Int? = null,
)

@Serializable
data class TimeTrapResolutionRequest(
    val playerUuid: String,
    val confirmed: Boolean,
)
