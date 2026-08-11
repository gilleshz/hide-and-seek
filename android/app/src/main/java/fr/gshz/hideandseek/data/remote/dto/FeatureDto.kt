package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class FeatureDto(
    val uuid: String,
    val name: String? = null,
    val lat: Double,
    val lng: Double,
)
