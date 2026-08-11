package fr.gshz.hideandseek.domain.model

data class LeaderboardEntry(
    val roundUuid: String,
    val roundNumber: Int,
    val hiderNames: List<String>,
    val hidingTimeSeconds: Long,
    val scoreSeconds: Long,
    val bonusMinutes: Int,
    val bonusPercent: Int,
)
