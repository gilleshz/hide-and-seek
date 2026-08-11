package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class TeamRequest(
    val playerUuid: String,
    val side: String,
)

@Serializable
data class TeamDto(
    val playerUuid: String,
    val roundUuid: String,
    val side: String,
    val mercureToken: String,
    val topics: List<String>,
)
