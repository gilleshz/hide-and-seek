package fr.gshz.hideandseek.feature.question

import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.RulesVariant

const val CUSTOM_RADAR_SENTINEL = -1.0

data class RadiusPreset(val labelRes: Int, val meters: Double)

data class DistancePreset(val labelRes: Int, val meters: Double, val minimumGameSize: GameSize)

private val METRIC_RADAR_PRESETS = listOf(
    RadiusPreset(labelRes = R.string.question_radar_500m, meters = 500.0),
    RadiusPreset(labelRes = R.string.question_radar_1km, meters = 1000.0),
    RadiusPreset(labelRes = R.string.question_radar_2km, meters = 2000.0),
    RadiusPreset(labelRes = R.string.question_radar_5km, meters = 5000.0),
    RadiusPreset(labelRes = R.string.question_radar_10km, meters = 10000.0),
    RadiusPreset(labelRes = R.string.question_radar_15km, meters = 15000.0),
    RadiusPreset(labelRes = R.string.question_radar_40km, meters = 40000.0),
    RadiusPreset(labelRes = R.string.question_radar_80km, meters = 80000.0),
    RadiusPreset(labelRes = R.string.question_radar_160km, meters = 160000.0),
    RadiusPreset(labelRes = R.string.question_radar_custom, meters = CUSTOM_RADAR_SENTINEL),
)

private val IMPERIAL_RADAR_PRESETS = listOf(
    RadiusPreset(labelRes = R.string.question_radar_quarter_mi, meters = 402.336),
    RadiusPreset(labelRes = R.string.question_radar_half_mi, meters = 804.672),
    RadiusPreset(labelRes = R.string.question_radar_1mi, meters = 1609.344),
    RadiusPreset(labelRes = R.string.question_radar_3mi, meters = 4828.032),
    RadiusPreset(labelRes = R.string.question_radar_5mi, meters = 8046.72),
    RadiusPreset(labelRes = R.string.question_radar_10mi, meters = 16093.44),
    RadiusPreset(labelRes = R.string.question_radar_25mi, meters = 40233.6),
    RadiusPreset(labelRes = R.string.question_radar_50mi, meters = 80467.2),
    RadiusPreset(labelRes = R.string.question_radar_100mi, meters = 160934.4),
    RadiusPreset(labelRes = R.string.question_radar_custom, meters = CUSTOM_RADAR_SENTINEL),
)

private val METRIC_THERMOMETER_PRESETS = listOf(
    DistancePreset(labelRes = R.string.question_thermo_1km, meters = 1000.0, minimumGameSize = GameSize.Small),
    DistancePreset(labelRes = R.string.question_thermo_5km, meters = 5000.0, minimumGameSize = GameSize.Small),
    DistancePreset(labelRes = R.string.question_thermo_15km, meters = 15000.0, minimumGameSize = GameSize.Medium),
    DistancePreset(labelRes = R.string.question_thermo_75km, meters = 75000.0, minimumGameSize = GameSize.Large),
)

private val IMPERIAL_THERMOMETER_PRESETS = listOf(
    DistancePreset(labelRes = R.string.question_thermo_half_mi, meters = 804.672, minimumGameSize = GameSize.Small),
    DistancePreset(labelRes = R.string.question_thermo_3mi, meters = 4828.032, minimumGameSize = GameSize.Small),
    DistancePreset(labelRes = R.string.question_thermo_10mi, meters = 16093.44, minimumGameSize = GameSize.Medium),
    DistancePreset(labelRes = R.string.question_thermo_50mi, meters = 80467.2, minimumGameSize = GameSize.Large),
)

/** A compact game swaps the Small upper rung: 5 km becomes 2 km, 3 mi becomes 1 mi. */
private val METRIC_THERMOMETER_COMPACT_PRESETS = listOf(
    DistancePreset(labelRes = R.string.question_thermo_1km, meters = 1000.0, minimumGameSize = GameSize.Small),
    DistancePreset(labelRes = R.string.question_thermo_2km, meters = 2000.0, minimumGameSize = GameSize.Small),
    DistancePreset(labelRes = R.string.question_thermo_15km, meters = 15000.0, minimumGameSize = GameSize.Medium),
    DistancePreset(labelRes = R.string.question_thermo_75km, meters = 75000.0, minimumGameSize = GameSize.Large),
)

private val IMPERIAL_THERMOMETER_COMPACT_PRESETS = listOf(
    DistancePreset(labelRes = R.string.question_thermo_half_mi, meters = 804.672, minimumGameSize = GameSize.Small),
    DistancePreset(labelRes = R.string.question_thermo_1mi, meters = 1609.344, minimumGameSize = GameSize.Small),
    DistancePreset(labelRes = R.string.question_thermo_10mi, meters = 16093.44, minimumGameSize = GameSize.Medium),
    DistancePreset(labelRes = R.string.question_thermo_50mi, meters = 80467.2, minimumGameSize = GameSize.Large),
)

object QuestionPresets {

    fun radarPresets(edition: Edition): List<RadiusPreset> = when (edition) {
        Edition.Metric -> METRIC_RADAR_PRESETS
        Edition.Imperial -> IMPERIAL_RADAR_PRESETS
    }

    fun thermometerPresets(
        edition: Edition,
        gameSize: GameSize,
        rulesVariant: RulesVariant,
    ): List<DistancePreset> {
        val presets = when (rulesVariant) {
            RulesVariant.Official -> when (edition) {
                Edition.Metric -> METRIC_THERMOMETER_PRESETS
                Edition.Imperial -> IMPERIAL_THERMOMETER_PRESETS
            }
            RulesVariant.Compact -> when (edition) {
                Edition.Metric -> METRIC_THERMOMETER_COMPACT_PRESETS
                Edition.Imperial -> IMPERIAL_THERMOMETER_COMPACT_PRESETS
            }
        }
        return presets.filter { gameSize >= it.minimumGameSize }
    }
}
