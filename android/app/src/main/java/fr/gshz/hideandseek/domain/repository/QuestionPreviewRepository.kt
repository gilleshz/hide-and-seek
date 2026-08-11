package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.data.remote.dto.FeatureDto

data class QuestionPreviewRequest(
    val roundUuid: String,
    val askerPlayerUuid: String,
    val category: String,
    val seekerLat: Double,
    val seekerLng: Double,
    val endLat: Double?,
    val endLng: Double?,
    val radiusMeters: Int?,
    val featureType: String? = null,
    val hypotheticalFeatureId: String? = null,
    val withinMeters: Int? = null,
    val hypotheticalAnswer: String,
)

data class QuestionPreviewResult(
    val constraintGeoJson: String?,
    val currentAreaKm2: Double,
    val projectedAreaKm2: Double,
    val currentPossibleAreaGeoJson: String?,
    val projectedPossibleAreaGeoJson: String?,
    val excludedPossibleAreaGeoJson: String?,
)

interface QuestionPreviewRepository {
    suspend fun preview(request: QuestionPreviewRequest): QuestionPreviewResult
    suspend fun getFeatures(url: String): List<FeatureDto>
}
