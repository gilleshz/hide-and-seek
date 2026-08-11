package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class IngestFeaturesResponseDto(
    val featuresIngested: Int = 0,
)
