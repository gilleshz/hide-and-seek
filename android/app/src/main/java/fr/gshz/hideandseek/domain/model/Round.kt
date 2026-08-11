package fr.gshz.hideandseek.domain.model

data class Round(
    val roundUuid: String,
    val status: RoundStatus?,
    val hidingPeriodStartedAtMillis: Long?,
    val hidingPeriodEndsAtMillis: Long?,
    val seekingEndedAtMillis: Long?,
    val hidingTimeSeconds: Int?,
    val hidingRadiusMeters: Double? = null,
    // The radius falls back to the game default, so only this says whether a zone was ever set.
    val hasHidingZone: Boolean = false,
    // Seeking time earned before a Move paused the clock; the live counter has to add it back.
    val bankedSeekingSeconds: Int = 0,
    val inMovePeriod: Boolean = false,
    val bonusMinutes: Int = 0,
    val bonusPercent: Int = 0,
    // hidingTimeSeconds is the raw run; this is what the time-bonus cards turned it into.
    val scoreSeconds: Int? = null,
)
