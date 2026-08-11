package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class PossibleAreaDto(
    val roundUuid: String,
    val geoJson: String? = null,
    val exclusionGeoJson: String? = null,
)
