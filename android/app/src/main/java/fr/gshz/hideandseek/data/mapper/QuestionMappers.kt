package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.AskedQuestionDto
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.FeatureType
import fr.gshz.hideandseek.domain.model.MeasuringResult
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.QuestionStatus
import fr.gshz.hideandseek.domain.model.ThermometerResult

fun AskedQuestionDto.toDomain(): AskedQuestion? {
    val mappedCategory = QuestionCategory.fromWireValue(category) ?: return null
    return AskedQuestion(
        uuid = uuid,
        roundUuid = roundUuid,
        category = mappedCategory,
        askedAt = askedAt,
        revealDeadlineAt = revealDeadlineAt,
        revealedAt = revealedAt,
        featureType = featureType?.let(FeatureType::fromWireValue),
        withinMeters = withinMeters,
        radarAnswer = radarAnswer,
        thermometerResult = thermometerAnswer?.let(ThermometerResult::fromWireValue),
        matchingAnswer = matchingAnswer,
        transitLineLabel = transitLineLabel,
        measuringResult = measuringAnswer?.let(MeasuringResult::fromWireValue),
        tentaclesAnswer = tentaclesAnswer,
        radiusMeters = radiusMeters,
        distanceMeters = distanceMeters,
        seekerLat = seekerLat,
        seekerLng = seekerLng,
        startLat = startLat,
        startLng = startLng,
        endLat = endLat,
        endLng = endLng,
        photoTarget = photoTarget?.let(PhotoTarget::fromWireValue),
        status = status?.let(QuestionStatus::fromWireValue) ?: QuestionStatus.Open,
        replacedByUuid = replacedByUuid,
        replacedQuestionUuid = replacedQuestionUuid,
        repeatCount = repeatCount,
        isCustomRadius = isCustomRadius,
    )
}
