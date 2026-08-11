package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class LeaveRequest(
    val playerUuid: String,
)

@Serializable
data class LeaveResponseDto(
    val gameUuid: String,
    val playerUuid: String,
    val removed: Boolean,
)

@Serializable
data class DeleteGameRequest(
    val playerUuid: String,
)
