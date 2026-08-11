package fr.gshz.hideandseek.core.location

import android.location.Location
import android.os.Build

fun pickAltitudeMeters(
    hasMslAltitude: Boolean,
    mslAltitudeMeters: Double,
    hasAltitude: Boolean,
    altitudeMeters: Double,
): Double? = when {
    hasMslAltitude -> mslAltitudeMeters
    hasAltitude -> altitudeMeters
    else -> null
}

fun Location.bestAltitudeMeters(): Double? =
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
        pickAltitudeMeters(hasMslAltitude(), mslAltitudeMeters, hasAltitude(), altitude)
    } else {
        pickAltitudeMeters(
            hasMslAltitude = false,
            mslAltitudeMeters = 0.0,
            hasAltitude = hasAltitude(),
            altitudeMeters = altitude,
        )
    }
