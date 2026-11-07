package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class TrafficAlertsDto(
    val serverNow: Long = 0,
    val alerts: List<TrafficAlertDto> = emptyList(),
)

@Serializable
data class TrafficAlertDto(
    val id: String = "",
    val channel: String = "",
    val severity: String = "info",
    val title: String = "",
    val body: String = "",
    val lineRefs: List<String> = emptyList(),
    val routeIds: List<String> = emptyList(),
    val stopIds: List<String> = emptyList(),
    // The backend omits null JSON fields, so every nullable field needs a default.
    val validFrom: Long? = null,
    val validUntil: Long? = null,
    val source: String = "",
)
