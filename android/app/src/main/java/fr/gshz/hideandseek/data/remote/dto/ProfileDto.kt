package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class ProfileDto(
    val name: String,
    val avatarRef: String? = null,
)
