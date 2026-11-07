package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.TrafficAlertDto
import fr.gshz.hideandseek.data.remote.dto.TrafficAlertsDto
import fr.gshz.hideandseek.domain.model.TrafficAlert
import fr.gshz.hideandseek.domain.model.TrafficAlertSeverity
import fr.gshz.hideandseek.domain.model.TrafficAlerts

fun TrafficAlertsDto.toDomain() = TrafficAlerts(
    serverNow = serverNow,
    alerts = alerts.map { it.toDomain() },
)

fun TrafficAlertDto.toDomain() = TrafficAlert(
    id = id,
    channel = channel,
    severity = TrafficAlertSeverity.fromWireValue(severity),
    title = title,
    body = body,
    lineRefs = lineRefs,
    routeIds = routeIds,
    stopIds = stopIds,
    validFrom = validFrom,
    validUntil = validUntil,
    source = source,
)
