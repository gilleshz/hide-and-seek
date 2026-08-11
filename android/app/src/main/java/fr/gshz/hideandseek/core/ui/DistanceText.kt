package fr.gshz.hideandseek.core.ui

import androidx.compose.runtime.Composable
import androidx.compose.ui.res.stringResource
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.domain.model.Edition
import kotlin.math.floor
import kotlin.math.roundToInt

/** Distances read the same wherever they appear: the chat answers and the live trace length agree. */
@Composable
fun formatDistance(meters: Double, edition: Edition): String = when {
    edition == Edition.Imperial -> stringResource(R.string.distance_miles, trimmed(meters / METERS_PER_MILE))
    meters < METERS_PER_KM -> stringResource(R.string.distance_meters, meters.roundToInt())
    else -> stringResource(R.string.distance_km, trimmed(meters / METERS_PER_KM))
}

private fun trimmed(value: Double): String {
    val rounded = (value * TENTHS).roundToInt() / TENTHS
    return if (rounded == floor(rounded)) rounded.toInt().toString() else rounded.toString()
}

private const val METERS_PER_KM = 1000.0
private const val METERS_PER_MILE = 1609.344
private const val TENTHS = 10.0
