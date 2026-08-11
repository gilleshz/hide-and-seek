package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.FeatureDto
import fr.gshz.hideandseek.data.remote.dto.QuestionPreviewRequest as ApiQuestionPreviewRequest
import fr.gshz.hideandseek.domain.repository.QuestionPreviewRepository
import fr.gshz.hideandseek.domain.repository.QuestionPreviewRequest
import fr.gshz.hideandseek.domain.repository.QuestionPreviewResult
import javax.inject.Inject

class QuestionPreviewRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
) : QuestionPreviewRepository {

    override suspend fun preview(request: QuestionPreviewRequest): QuestionPreviewResult {
        val dto = api.previewQuestion(
            url = urlFor("/api/rounds/${request.roundUuid}/question-preview"),
            body = ApiQuestionPreviewRequest(
                askerPlayerUuid = request.askerPlayerUuid,
                category = request.category,
                seekerLat = request.seekerLat,
                seekerLng = request.seekerLng,
                endLat = request.endLat,
                endLng = request.endLng,
                radiusMeters = request.radiusMeters,
                featureType = request.featureType,
                hypotheticalFeatureId = request.hypotheticalFeatureId,
                hypotheticalAnswer = request.hypotheticalAnswer,
            ),
        )
        return QuestionPreviewResult(
            constraintGeoJson = dto.constraintGeoJson,
            currentAreaKm2 = dto.currentAreaKm2,
            projectedAreaKm2 = dto.projectedAreaKm2,
            currentPossibleAreaGeoJson = dto.currentPossibleAreaGeoJson,
            projectedPossibleAreaGeoJson = dto.projectedPossibleAreaGeoJson,
            excludedPossibleAreaGeoJson = dto.excludedPossibleAreaGeoJson,
        )
    }

    override suspend fun getFeatures(url: String): List<FeatureDto> = api.getFeatures(url)

    private suspend fun urlFor(path: String): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/') + path
}
