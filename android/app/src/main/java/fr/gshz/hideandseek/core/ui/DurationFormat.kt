package fr.gshz.hideandseek.core.ui

import android.content.Context
import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.LocalContext
import fr.gshz.hideandseek.R

/**
 * Running-clock format: `H:MM:SS` past the first hour, `M:SS` below. Games run long,
 * so a bare minute count would read as nonsense.
 */
fun formatDuration(totalSeconds: Long): String {
    val (hours, minutes, seconds) = durationParts(totalSeconds)

    return if (hours > 0) {
        "%d:%02d:%02d".format(hours, minutes, seconds)
    } else {
        "%d:%02d".format(minutes, seconds)
    }
}

/**
 * Worded format for a finished duration, score or total: "2 h 14 min 08 s". Results read as
 * measurements, so they carry units in the locale's abbreviations.
 */
fun formatDurationWords(context: Context, totalSeconds: Long): String {
    val (hours, minutes, seconds) = durationParts(totalSeconds)

    return if (hours > 0) {
        context.getString(R.string.duration_hms, hours, minutes, seconds)
    } else {
        context.getString(R.string.duration_ms, minutes, seconds)
    }
}

@Composable
fun formatDurationWords(totalSeconds: Long): String = formatDurationWords(LocalContext.current, totalSeconds)

/** A duration that ran backwards is a clock nobody can read, so it reads as zero instead. */
internal fun durationParts(totalSeconds: Long): DurationParts {
    val safe = totalSeconds.coerceAtLeast(0L)

    return DurationParts(
        hours = safe / SECONDS_PER_HOUR,
        minutes = safe % SECONDS_PER_HOUR / SECONDS_PER_MINUTE,
        seconds = safe % SECONDS_PER_MINUTE,
    )
}

internal data class DurationParts(val hours: Long, val minutes: Long, val seconds: Long)

private const val SECONDS_PER_MINUTE = 60L
private const val SECONDS_PER_HOUR = 3600L
