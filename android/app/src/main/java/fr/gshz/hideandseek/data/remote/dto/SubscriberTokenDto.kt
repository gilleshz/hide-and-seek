package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class SubscriberTokenDto(
    val playerUuid: String,
    val roundUuid: String,
    val mercureToken: String,
    val topics: List<String>,
)
