package fr.gshz.hideandseek.feature.map

import androidx.compose.runtime.Composable
import androidx.compose.ui.res.stringResource
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.feature.question.QuestionPresets

@Composable
internal fun zoneRadiusLabel(radiusMeters: Double, edition: Edition): String {
    val preset = QuestionPresets.radarPresets(edition).firstOrNull { it.meters == radiusMeters }
    return if (preset != null) {
        stringResource(preset.labelRes)
    } else {
        stringResource(R.string.zone_radius_value, radiusMeters.toInt())
    }
}

@Composable
internal fun selectedZoneRadiusLabel(selectedRadiusMeters: Double?, edition: Edition): String =
    selectedRadiusMeters?.let { zoneRadiusLabel(it, edition) } ?: stringResource(R.string.zone_radius_default)

@Composable
internal fun defaultZoneRadiusLabel(edition: Edition): String {
    val defaultMeters = QuestionPresets.radarPresets(edition).firstOrNull()?.meters ?: 500.0
    return zoneRadiusLabel(defaultMeters, edition)
}
