package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.RoundDto
import fr.gshz.hideandseek.domain.model.Round
import fr.gshz.hideandseek.domain.model.RoundStatus
import java.time.OffsetDateTime

// An unknown wire status maps to null (same tolerance as the SSE path) instead of crashing the caller.
fun RoundDto.toDomain() = Round(
    roundUuid = roundUuid,
    status = RoundStatus.fromWireValueOrNull(status),
    hidingPeriodStartedAtMillis = hidingPeriodStartedAt?.toEpochMillis(),
    hidingPeriodEndsAtMillis = hidingPeriodEndsAt?.toEpochMillis(),
    seekingEndedAtMillis = seekingEndedAt?.toEpochMillis(),
    hidingTimeSeconds = hidingTimeSeconds,
    hidingRadiusMeters = hidingRadiusMeters,
    hasHidingZone = hasHidingZone,
    bankedSeekingSeconds = bankedSeekingSeconds,
    inMovePeriod = inMovePeriod,
    bonusMinutes = bonusMinutes,
    bonusPercent = bonusPercent,
    scoreSeconds = scoreSeconds,
)

// OffsetDateTime.parse accepts both the REST "+00:00" offset form and the Mercure "Z" form.
private fun String.toEpochMillis(): Long = OffsetDateTime.parse(this).toInstant().toEpochMilli()
