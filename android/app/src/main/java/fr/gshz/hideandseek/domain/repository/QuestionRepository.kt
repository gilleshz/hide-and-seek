package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.FeatureAskRequest
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.ThermometerAskRequest

interface QuestionRepository {
    suspend fun askRadarQuestion(
        roundUuid: String,
        askerPlayerUuid: String,
        radiusMeters: Double,
        seekerLat: Double,
        seekerLng: Double,
        isCustomRadius: Boolean = false,
    ): AskedQuestion

    suspend fun askThermometerQuestion(request: ThermometerAskRequest): AskedQuestion

    suspend fun completeThermometer(
        questionUuid: String,
        askerPlayerUuid: String,
        endLat: Double,
        endLng: Double,
    ): AskedQuestion

    suspend fun askFeatureQuestion(request: FeatureAskRequest): AskedQuestion

    suspend fun askPhotoQuestion(
        roundUuid: String,
        askerPlayerUuid: String,
        photoTarget: PhotoTarget,
    ): AskedQuestion

    suspend fun getQuestion(questionUuid: String): AskedQuestion
    suspend fun listQuestions(roundUuid: String): List<AskedQuestion>
    suspend fun revealQuestion(questionUuid: String, revealingPlayerUuid: String): AskedQuestion
    suspend fun revealPhotoQuestion(questionUuid: String, playerUuid: String, imageUri: String): AskedQuestion
    suspend fun cancelQuestion(questionUuid: String, askerPlayerUuid: String)
    suspend fun vetoQuestion(questionUuid: String, playerUuid: String, cardPhotoUri: String)
    suspend fun randomizeQuestion(questionUuid: String, playerUuid: String, cardPhotoUri: String): AskedQuestion
}
