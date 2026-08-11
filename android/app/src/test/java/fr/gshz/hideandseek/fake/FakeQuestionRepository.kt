package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.FeatureAskRequest
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.QuestionStatus
import fr.gshz.hideandseek.domain.model.ThermometerAskRequest
import fr.gshz.hideandseek.domain.repository.QuestionRepository

class FakeQuestionRepository : QuestionRepository {
    var questions: MutableList<AskedQuestion> = mutableListOf()
    var askRadarResult: Result<AskedQuestion>? = null
    var askThermometerResult: Result<AskedQuestion>? = null
    var completeThermometerResult: Result<AskedQuestion>? = null
    var askFeatureResult: Result<AskedQuestion>? = null
    var askPhotoResult: Result<AskedQuestion>? = null
    var revealResult: Result<AskedQuestion>? = null
    var randomizeResult: Result<AskedQuestion>? = null

    val askedRadarCalls = mutableListOf<Double>()
    val askedThermometerCalls = mutableListOf<ThermometerAskRequest>()
    val completedThermometerCalls = mutableListOf<CompleteThermometerCall>()
    val askedFeatureCalls = mutableListOf<FeatureAskRequest>()
    val askedPhotoCalls = mutableListOf<PhotoTarget>()
    val revealedCalls = mutableListOf<String>()
    val vetoedCalls = mutableListOf<String>()
    val randomizedCalls = mutableListOf<String>()

    data class CompleteThermometerCall(
        val questionUuid: String,
        val askerPlayerUuid: String,
        val endLat: Double,
        val endLng: Double,
    )

    override suspend fun askRadarQuestion(
        roundUuid: String,
        askerPlayerUuid: String,
        radiusMeters: Double,
        seekerLat: Double,
        seekerLng: Double,
        isCustomRadius: Boolean,
    ): AskedQuestion {
        askedRadarCalls += radiusMeters
        val question = askRadarResult?.getOrThrow() ?: defaultQuestion(QuestionCategory.Radar)
        questions += question
        return question
    }

    override suspend fun askThermometerQuestion(request: ThermometerAskRequest): AskedQuestion {
        askedThermometerCalls += request
        val question = askThermometerResult?.getOrThrow() ?: defaultQuestion(QuestionCategory.Thermometer)
        questions += question
        return question
    }

    override suspend fun completeThermometer(
        questionUuid: String,
        askerPlayerUuid: String,
        endLat: Double,
        endLng: Double,
    ): AskedQuestion {
        completedThermometerCalls += CompleteThermometerCall(questionUuid, askerPlayerUuid, endLat, endLng)
        val completed = completeThermometerResult?.getOrThrow()
            ?: (questions.firstOrNull { it.uuid == questionUuid } ?: defaultQuestion(QuestionCategory.Thermometer))
                .copy(revealDeadlineAt = "2026-01-01T00:05:00Z", endLat = endLat, endLng = endLng)
        questions = questions.map { if (it.uuid == completed.uuid) completed else it }.toMutableList()
        return completed
    }

    override suspend fun askFeatureQuestion(request: FeatureAskRequest): AskedQuestion {
        askedFeatureCalls += request
        val question = askFeatureResult?.getOrThrow() ?: defaultQuestion(request.category)
        questions += question
        return question
    }

    override suspend fun askPhotoQuestion(
        roundUuid: String,
        askerPlayerUuid: String,
        photoTarget: PhotoTarget,
    ): AskedQuestion {
        askedPhotoCalls += photoTarget
        val question = askPhotoResult?.getOrThrow()
            ?: defaultQuestion(QuestionCategory.Photos).copy(photoTarget = photoTarget)
        questions += question
        return question
    }

    override suspend fun getQuestion(questionUuid: String): AskedQuestion =
        questions.first { it.uuid == questionUuid }

    var listQuestionsCalls = 0

    override suspend fun listQuestions(roundUuid: String): List<AskedQuestion> {
        listQuestionsCalls++
        return questions.toList()
    }

    override suspend fun revealQuestion(questionUuid: String, revealingPlayerUuid: String): AskedQuestion {
        revealedCalls += questionUuid
        val revealed = revealResult?.getOrThrow() ?: questions.first { it.uuid == questionUuid }.copy(
            revealedAt = "2026-01-01T00:05:00Z",
            radarAnswer = true,
        )
        questions = questions.map { if (it.uuid == questionUuid) revealed else it }.toMutableList()
        return revealed
    }

    var revealPhotoResult: Result<AskedQuestion>? = null
    val revealedPhotoCalls = mutableListOf<Pair<String, String>>()

    override suspend fun revealPhotoQuestion(
        questionUuid: String,
        playerUuid: String,
        imageUri: String,
    ): AskedQuestion {
        revealedPhotoCalls += questionUuid to imageUri
        val revealed = revealPhotoResult?.getOrThrow() ?: questions.first { it.uuid == questionUuid }.copy(
            revealedAt = "2026-01-01T00:05:00Z",
        )
        questions = questions.map { if (it.uuid == questionUuid) revealed else it }.toMutableList()
        return revealed
    }

    override suspend fun cancelQuestion(questionUuid: String, askerPlayerUuid: String) {
        questions = questions.filter { it.uuid != questionUuid }.toMutableList()
    }

    override suspend fun vetoQuestion(questionUuid: String, playerUuid: String, cardPhotoUri: String) {
        vetoedCalls += questionUuid
        questions = questions.map { q ->
            if (q.uuid == questionUuid) q.copy(status = QuestionStatus.Vetoed) else q
        }.toMutableList()
    }

    override suspend fun randomizeQuestion(
        questionUuid: String,
        playerUuid: String,
        cardPhotoUri: String,
    ): AskedQuestion {
        randomizedCalls += questionUuid
        randomizeResult?.let { return it.getOrThrow() }
        val original = questions.firstOrNull { it.uuid == questionUuid }
            ?: error("Question not found: $questionUuid")
        val replacement = defaultQuestion(original.category).copy(
            replacedQuestionUuid = questionUuid,
        )
        questions = questions.map { q ->
            if (q.uuid == questionUuid) q.copy(
                status = QuestionStatus.Randomized,
                replacedByUuid = replacement.uuid,
            ) else q
        }.toMutableList()
        questions += replacement
        return replacement
    }

    private fun defaultQuestion(category: QuestionCategory) = AskedQuestion(
        uuid = "question-${questions.size + 1}",
        roundUuid = "round-1",
        category = category,
        askedAt = "2026-01-01T00:00:00Z",
        revealDeadlineAt = "2026-01-01T00:05:00Z",
        revealedAt = null,
        radarAnswer = null,
        thermometerResult = null,
    )
}
