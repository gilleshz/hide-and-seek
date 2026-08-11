package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class PlayerDto(
    val uuid: String,
    val displayName: String,
    val side: String? = null,
)
