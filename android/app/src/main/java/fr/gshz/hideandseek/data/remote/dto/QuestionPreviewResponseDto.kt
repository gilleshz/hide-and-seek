package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class QuestionPreviewResponseDto(
    val id: String = "",
    val constraintGeoJson: String? = null,
    val currentPossibleAreaGeoJson: String? = null,
    val projectedPossibleAreaGeoJson: String? = null,
    val excludedPossibleAreaGeoJson: String? = null,
    val currentAreaKm2: Double = 0.0,
    val projectedAreaKm2: Double = 0.0,
)
