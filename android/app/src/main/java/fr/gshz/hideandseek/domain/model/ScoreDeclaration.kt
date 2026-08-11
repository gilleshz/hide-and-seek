package fr.gshz.hideandseek.domain.model

/**
 * What the hiders declare when they stop the round: the time-bonus cards they still held, plus the
 * hiding time the app had on screen when it froze the clock to collect them.
 */
data class ScoreDeclaration(
    val bonusMinutes: Int = 0,
    val bonusPercent: Int = 0,
    val hidingSeconds: Long? = null,
) {
    /** Percentage cards each count off the hiding time alone, so several of them add up. */
    fun percentSecondsFor(hidingSeconds: Long): Long =
        hidingSeconds.coerceAtLeast(0L) * bonusPercent / PERCENT

    fun bonusSecondsFor(hidingSeconds: Long): Long =
        bonusMinutes * SECONDS_PER_MINUTE + percentSecondsFor(hidingSeconds)

    fun totalSecondsFor(hidingSeconds: Long): Long = hidingSeconds + bonusSecondsFor(hidingSeconds)

    private companion object {
        const val SECONDS_PER_MINUTE = 60L
        const val PERCENT = 100L
    }
}
