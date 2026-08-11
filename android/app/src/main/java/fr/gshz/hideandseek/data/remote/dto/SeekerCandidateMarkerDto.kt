package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class SeekerCandidateMarkerDto(
    val uuid: String,
    val playerUuid: String? = null,
    val lat: Double,
    val lng: Double,
    val createdAt: String? = null,
)

@Serializable
data class AddSeekerCandidateMarkerRequest(
    val playerUuid: String,
    val lat: Double,
    val lng: Double,
)
