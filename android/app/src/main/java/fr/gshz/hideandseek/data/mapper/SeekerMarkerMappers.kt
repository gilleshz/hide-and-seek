package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.SeekerCandidateMarkerDto
import fr.gshz.hideandseek.domain.model.SeekerMarker

fun SeekerCandidateMarkerDto.toDomain() = SeekerMarker(
    uuid = uuid,
    playerUuid = playerUuid,
    lat = lat,
    lng = lng,
)
