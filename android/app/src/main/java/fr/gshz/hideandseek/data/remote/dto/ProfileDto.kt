package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class ProfileDto(
    val uuid: String? = null,
    val name: String,
    val avatarRef: String? = null,
)

@Serializable
data class ProfileNameRequest(
    val name: String,
)
