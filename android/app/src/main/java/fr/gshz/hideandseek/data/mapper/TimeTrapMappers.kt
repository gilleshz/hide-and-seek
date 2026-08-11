package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.TimeTrapDto
import fr.gshz.hideandseek.domain.model.TimeTrap
import fr.gshz.hideandseek.domain.model.TimeTrapStatus
import java.time.OffsetDateTime
import java.time.format.DateTimeParseException

/**
 * A trap whose anchor cannot be parsed starts accruing from now rather than from 1970, so a bad
 * timestamp reads as a fresh trap worth nothing instead of one worth centuries of intervals.
 */
fun TimeTrapDto.toDomain(nowMillis: Long = System.currentTimeMillis()) = TimeTrap(
    uuid = uuid,
    roundUuid = roundUuid,
    stationName = stationName,
    lat = lat,
    lng = lng,
    placedAtEpochMs = placedAt?.toEpochMillisOrNull() ?: nowMillis,
    status = TimeTrapStatus.fromWireValueOrNull(status) ?: TimeTrapStatus.Armed,
    valueSeconds = valueSeconds,
    intervalMinutes = intervalMinutes,
    incrementMinutes = incrementMinutes,
    detectedByName = detectedByName,
    awardedSeconds = awardedSeconds,
)

internal fun String.toEpochMillisOrNull(): Long? = try {
    OffsetDateTime.parse(this).toInstant().toEpochMilli()
} catch (_: DateTimeParseException) {
    null
}
