package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.DepartureDto
import fr.gshz.hideandseek.data.remote.dto.DepartureGroupDto
import fr.gshz.hideandseek.data.remote.dto.StationDeparturesDto
import fr.gshz.hideandseek.domain.model.TransitDeparture
import fr.gshz.hideandseek.domain.model.TransitDepartureGroup
import fr.gshz.hideandseek.domain.model.TransitDepartures

fun StationDeparturesDto.toDomain() = TransitDepartures(
    stationId = stationId,
    stationName = stationName,
    timezone = timezone,
    serverNow = serverNow,
    groups = groups.map { it.toDomain() },
)

fun DepartureGroupDto.toDomain() = TransitDepartureGroup(
    routeId = routeId,
    lineRef = lineRef,
    lineName = lineName,
    colour = colour,
    headsign = headsign,
    directionId = directionId,
    realtime = realtime,
    departures = departures.map { it.toDomain() },
)

fun DepartureDto.toDomain() = TransitDeparture(
    at = at,
    inSeconds = inSeconds,
    realtime = realtime,
    delaySeconds = delaySeconds,
    headsign = headsign,
)
