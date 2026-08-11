package fr.gshz.hideandseek.domain.model

/**
 * A trap the hiders left on a transit station. Its value is not stored anywhere: it is
 * `floor(elapsed / interval) * increment` from [placedAtEpochMs] alone, so every client re-derives
 * the same number the server would. Worth nothing for its whole first interval, by design.
 */
data class TimeTrap(
    val uuid: String,
    val roundUuid: String,
    val stationName: String?,
    val lat: Double,
    val lng: Double,
    val placedAtEpochMs: Long,
    val status: TimeTrapStatus,
    val valueSeconds: Int,
    val intervalMinutes: Int,
    val incrementMinutes: Int,
    val detectedByName: String? = null,
    val awardedSeconds: Int? = null,
)

enum class TimeTrapStatus(val wireValue: String) {
    Armed("armed"),
    Pending("pending"),
    Sprung("sprung"),
    ;

    companion object {
        fun fromWireValueOrNull(value: String?): TimeTrapStatus? = entries.find { it.wireValue == value }
    }
}

/**
 * A detection freezes the value at the moment of the pass, so only an armed trap keeps
 * accruing; anything else reports the figure the server already fixed.
 */
fun TimeTrap.valueSecondsAt(nowMillis: Long): Int {
    val intervalMillis = intervalMinutes.toLong() * SECONDS_PER_MINUTE * MILLIS_PER_SECOND
    val elapsed = nowMillis - placedAtEpochMs
    return when {
        status != TimeTrapStatus.Armed || intervalMillis <= 0L -> valueSeconds
        elapsed < 0L -> 0
        else -> (elapsed / intervalMillis * incrementMinutes * SECONDS_PER_MINUTE).toInt()
    }
}

private const val SECONDS_PER_MINUTE = 60L
private const val MILLIS_PER_SECOND = 1_000L
