package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.HidingZoneDto
import fr.gshz.hideandseek.domain.model.HidingZone

fun HidingZoneDto.toDomain() = HidingZone(
    roundUuid = roundUuid,
    lat = lat,
    lng = lng,
    radiusMeters = radiusMeters,
    stationName = stationName,
)
