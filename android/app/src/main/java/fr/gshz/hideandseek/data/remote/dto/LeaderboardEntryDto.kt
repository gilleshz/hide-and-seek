package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class LeaderboardEntryDto(
    val roundUuid: String = "",
    val roundNumber: Int = 0,
    val hiderNames: List<String> = emptyList(),
    val hidingTimeSeconds: Long = 0,
    val scoreSeconds: Long = 0,
    val bonusMinutes: Int = 0,
    val bonusPercent: Int = 0,
)
