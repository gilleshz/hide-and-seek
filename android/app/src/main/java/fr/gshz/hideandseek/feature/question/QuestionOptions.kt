package fr.gshz.hideandseek.feature.question

import fr.gshz.hideandseek.domain.model.FeatureType
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.QuestionCategory

/**
 * Feature types a category can target, per the game rules. Matching and Measuring have
 * distinct sets (Measuring adds transit lines, coastline, body of water; Matching has neither);
 * Tentacles is unavailable in Small games and size-gated. Transit Line matching is a separate chip.
 */
internal fun availableFeatureTypes(category: QuestionCategory, size: GameSize): List<FeatureType> = when (category) {
    QuestionCategory.Matching -> MATCHING_TYPES
    QuestionCategory.Measuring -> MEASURING_TYPES
    QuestionCategory.Tentacles -> tentaclesTypes(size)
    QuestionCategory.Radar, QuestionCategory.Thermometer, QuestionCategory.Photos -> emptyList()
}

internal fun tentaclesRangeMeters(size: GameSize): Double = when (size) {
    GameSize.Large -> TENTACLES_LARGE_RANGE_M
    else -> TENTACLES_MEDIUM_RANGE_M
}

private fun tentaclesTypes(size: GameSize): List<FeatureType> = when (size) {
    GameSize.Small -> emptyList()
    GameSize.Medium -> TENTACLES_MEDIUM_TYPES
    GameSize.Large -> TENTACLES_MEDIUM_TYPES + TENTACLES_LARGE_EXTRA_TYPES
}

private val MATCHING_TYPES = listOf(
    FeatureType.CommercialAirport,
    FeatureType.AdminBoundary1st,
    FeatureType.AdminBoundary2nd,
    FeatureType.AdminBoundary3rd,
    FeatureType.AdminBoundary4th,
    FeatureType.Mountain,
    FeatureType.Park,
    FeatureType.AmusementPark,
    FeatureType.Zoo,
    FeatureType.Aquarium,
    FeatureType.GolfCourse,
    FeatureType.Museum,
    FeatureType.MovieTheater,
    FeatureType.Hospital,
    FeatureType.Library,
    FeatureType.Consulate,
)

private val MEASURING_TYPES = listOf(
    FeatureType.CommercialAirport,
    FeatureType.RailStation,
    FeatureType.HighSpeedRailLine,
    FeatureType.BorderInternational,
    FeatureType.Border1st,
    FeatureType.Border2nd,
    FeatureType.Coastline,
    FeatureType.BodyOfWater,
    FeatureType.Mountain,
    FeatureType.Park,
    FeatureType.AmusementPark,
    FeatureType.Zoo,
    FeatureType.Aquarium,
    FeatureType.GolfCourse,
    FeatureType.Museum,
    FeatureType.MovieTheater,
    FeatureType.Hospital,
    FeatureType.Library,
    FeatureType.Consulate,
)

private val TENTACLES_MEDIUM_TYPES = listOf(
    FeatureType.Museum,
    FeatureType.Library,
    FeatureType.MovieTheater,
    FeatureType.Hospital,
)

private val TENTACLES_LARGE_EXTRA_TYPES = listOf(
    FeatureType.MetroLine,
    FeatureType.Zoo,
    FeatureType.Aquarium,
    FeatureType.AmusementPark,
)

private const val TENTACLES_MEDIUM_RANGE_M = 2000.0
private const val TENTACLES_LARGE_RANGE_M = 25000.0
