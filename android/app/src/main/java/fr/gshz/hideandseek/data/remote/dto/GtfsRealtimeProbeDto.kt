package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class GtfsRealtimeProbeRequest(
    val url: String,
)

@Serializable
data class GtfsRealtimeProbeDto(
    val status: String = "",
    val feedType: String? = null,
    val headerAgeSeconds: Int? = null,
    val tripCount: Int = 0,
)
