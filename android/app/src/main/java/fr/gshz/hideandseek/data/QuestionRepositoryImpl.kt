package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.mapper.toDomain
import fr.gshz.hideandseek.data.remote.ImageParts
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.AskQuestionRequest
import fr.gshz.hideandseek.data.remote.dto.AskedQuestionDto
import fr.gshz.hideandseek.data.remote.dto.CancelQuestionRequest
import fr.gshz.hideandseek.data.remote.dto.CompleteThermometerRequest
import fr.gshz.hideandseek.data.remote.dto.RevealQuestionRequest
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.FeatureAskRequest
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.ThermometerAskRequest
import fr.gshz.hideandseek.domain.repository.QuestionRepository
import java.io.IOException
import javax.inject.Inject

class QuestionRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
    private val imageParts: ImageParts,
) : QuestionRepository {

    override suspend fun askRadarQuestion(
        roundUuid: String,
        askerPlayerUuid: String,
        radiusMeters: Double,
        seekerLat: Double,
        seekerLng: Double,
        isCustomRadius: Boolean,
    ): AskedQuestion = api.askQuestion(
        url = urlFor("/api/rounds/$roundUuid/questions"),
        body = AskQuestionRequest(
            askerPlayerUuid = askerPlayerUuid,
            category = QuestionCategory.Radar.wireValue,
            radiusMeters = radiusMeters,
            seekerLat = seekerLat,
            seekerLng = seekerLng,
            isCustomRadius = isCustomRadius,
        ),
    ).toDomainOrThrow()

    override suspend fun askThermometerQuestion(request: ThermometerAskRequest): AskedQuestion = api.askQuestion(
        url = urlFor("/api/rounds/${request.roundUuid}/questions"),
        body = AskQuestionRequest(
            askerPlayerUuid = request.askerPlayerUuid,
            category = QuestionCategory.Thermometer.wireValue,
            startLat = request.startLat,
            startLng = request.startLng,
            distanceMeters = request.distanceMeters,
        ),
    ).toDomainOrThrow()

    override suspend fun completeThermometer(
        questionUuid: String,
        askerPlayerUuid: String,
        endLat: Double,
        endLng: Double,
    ): AskedQuestion = api.completeThermometer(
        url = urlFor("/api/questions/$questionUuid/complete"),
        body = CompleteThermometerRequest(
            askerPlayerUuid = askerPlayerUuid,
            endLat = endLat,
            endLng = endLng,
        ),
    ).toDomainOrThrow()

    override suspend fun askFeatureQuestion(request: FeatureAskRequest): AskedQuestion = api.askQuestion(
        url = urlFor("/api/rounds/${request.roundUuid}/questions"),
        body = AskQuestionRequest(
            askerPlayerUuid = request.askerPlayerUuid,
            category = request.category.wireValue,
            seekerLat = request.seekerLat,
            seekerLng = request.seekerLng,
            featureType = request.featureType?.wireValue,
            withinMeters = request.withinMeters,
            transitLineOsmId = request.transitLineOsmId,
            transitLineOsmType = request.transitLineOsmType,
            stationNameLength = request.stationNameLength,
            seaLevel = request.seaLevel,
            seekerAltitude = request.seekerAltitude,
        ),
    ).toDomainOrThrow()

    override suspend fun askPhotoQuestion(
        roundUuid: String,
        askerPlayerUuid: String,
        photoTarget: PhotoTarget,
    ): AskedQuestion = api.askQuestion(
        url = urlFor("/api/rounds/$roundUuid/questions"),
        body = AskQuestionRequest(
            askerPlayerUuid = askerPlayerUuid,
            category = QuestionCategory.Photos.wireValue,
            photoTarget = photoTarget.wireValue,
        ),
    ).toDomainOrThrow()

    override suspend fun getQuestion(questionUuid: String): AskedQuestion =
        api.getQuestion(url = urlFor("/api/questions/$questionUuid")).toDomainOrThrow()

    override suspend fun listQuestions(roundUuid: String): List<AskedQuestion> =
        api.listQuestions(url = urlFor("/api/rounds/$roundUuid/questions")).mapNotNull { it.toDomain() }

    override suspend fun revealQuestion(questionUuid: String, revealingPlayerUuid: String): AskedQuestion =
        api.revealQuestion(
            url = urlFor("/api/questions/$questionUuid/reveal"),
            body = RevealQuestionRequest(revealingPlayerUuid = revealingPlayerUuid),
        ).toDomainOrThrow()

    override suspend fun revealPhotoQuestion(
        questionUuid: String,
        playerUuid: String,
        imageUri: String,
    ): AskedQuestion = api.answerPhotoQuestion(
        url = urlFor("/api/questions/$questionUuid/answer-photo"),
        image = imageParts.image(imageUri),
        playerUuid = imageParts.text(playerUuid),
    ).toDomainOrThrow()

    override suspend fun cancelQuestion(questionUuid: String, askerPlayerUuid: String) {
        api.cancelQuestion(
            url = urlFor("/api/questions/$questionUuid/cancel"),
            body = CancelQuestionRequest(askerPlayerUuid = askerPlayerUuid),
        )
    }

    override suspend fun vetoQuestion(questionUuid: String, playerUuid: String, cardPhotoUri: String) {
        api.vetoQuestion(
            url = urlFor("/api/questions/$questionUuid/veto"),
            image = imageParts.image(cardPhotoUri),
            playerUuid = imageParts.text(playerUuid),
        )
    }

    override suspend fun randomizeQuestion(
        questionUuid: String,
        playerUuid: String,
        cardPhotoUri: String,
    ): AskedQuestion = api.randomizeQuestion(
        url = urlFor("/api/questions/$questionUuid/randomize"),
        image = imageParts.image(cardPhotoUri),
        playerUuid = imageParts.text(playerUuid),
    ).toDomainOrThrow()

    private fun AskedQuestionDto.toDomainOrThrow(): AskedQuestion =
        toDomain() ?: throw IOException("Unknown question category: $category")

    private suspend fun urlFor(path: String): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/') + path

}
