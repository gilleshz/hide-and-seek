package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class QuestionPreviewRequest(
    val askerPlayerUuid: String,
    val category: String,
    val seekerLat: Double,
    val seekerLng: Double,
    val endLat: Double? = null,
    val endLng: Double? = null,
    val radiusMeters: Int? = null,
    val featureType: String? = null,
    val hypotheticalFeatureId: String? = null,
    val withinMeters: Int? = null,
    val hypotheticalAnswer: String,
)
