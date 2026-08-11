package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class GtfsSourceUrlRequest(
    val url: String,
    val name: String,
)
