package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class RoundDto(
    val roundUuid: String,
    val status: String,
    val hidingPeriodStartedAt: String? = null,
    val hidingPeriodEndsAt: String? = null,
    val seekingEndedAt: String? = null,
    val hidingTimeSeconds: Int? = null,
    val hidingRadiusMeters: Double? = null,
    val hasHidingZone: Boolean = false,
    val bankedSeekingSeconds: Int = 0,
    val inMovePeriod: Boolean = false,
    val bonusMinutes: Int = 0,
    val bonusPercent: Int = 0,
    val scoreSeconds: Int? = null,
)

@Serializable
data class RoundStartRequest(
    val hidingPeriodMinutes: Int? = null,
)

@Serializable
data class RoundStopRequest(
    val bonusMinutes: Int? = null,
    val bonusPercent: Int? = null,
    val hidingSeconds: Int? = null,
    val caught: Boolean = false,
)
