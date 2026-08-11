package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class SetHidingZoneRequest(
    val playerUuid: String,
    val lat: Double,
    val lng: Double,
    val radiusMeters: Double? = null,
    val stationName: String? = null,
)

@Serializable
data class HidingZoneDto(
    val roundUuid: String = "",
    val lat: Double = 0.0,
    val lng: Double = 0.0,
    val radiusMeters: Double = 0.0,
    val stationName: String? = null,
)
