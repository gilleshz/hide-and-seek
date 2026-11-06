package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class GtfsSourceUrlRequest(
    val url: String,
    val name: String,
    val realtimeUrl: String? = null,
    val realtimeProvider: String? = null,
    val realtimeProfile: String? = null,
    val fallbackUrl: String? = null,
)
