package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class JoinRequest(
    val name: String,
    val password: String,
)

@Serializable
data class JoinDto(
    val playerUuid: String,
    val displayName: String,
    val gameUuid: String,
    val roundUuid: String,
    val mercureToken: String,
    val topics: List<String>,
)
