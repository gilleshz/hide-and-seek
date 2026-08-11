package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.PhotoTarget
import kotlin.math.PI
import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.min
import kotlin.math.sin
import kotlin.math.sqrt

fun traceLengthMeters(vertices: List<ZonePin>): Double =
    vertices.zipWithNext { from, to -> haversineMeters(from, to) }.sum()

fun projectTrace(
    vertices: List<ZonePin>,
    width: Int,
    height: Int,
    paddingFraction: Float,
): List<CanvasPoint> =
    projectTracePaths(listOf(vertices), width, height, paddingFraction).firstOrNull().orEmpty()

/**
 * Projects all polylines under one shared bounding box, so a disjoint or branchy selection keeps its
 * relative scale and position instead of each street filling the sheet alone.
 */
fun projectTracePaths(
    polylines: List<List<ZonePin>>,
    width: Int,
    height: Int,
    paddingFraction: Float,
): List<List<CanvasPoint>> {
    val all = polylines.flatten()
    if (all.isEmpty()) return emptyList()

    val minLatitude = all.minOf { it.latitude }
    val maxLatitude = all.maxOf { it.latitude }
    val minLongitude = all.minOf { it.longitude }
    val maxLongitude = all.maxOf { it.longitude }

    // Skipping this cosine stretches the drawing east-west until it stops matching the street.
    val longitudeScale = cos((minLatitude + maxLatitude) / 2.0 * DEG_TO_RAD)
    val spanX = (maxLongitude - minLongitude) * longitudeScale
    val spanY = maxLatitude - minLatitude

    val padding = paddingFraction.toDouble() * min(width, height)
    val usableWidth = (width - 2.0 * padding).coerceAtLeast(0.0)
    val usableHeight = (height - 2.0 * padding).coerceAtLeast(0.0)
    val scale = when {
        spanX > 0.0 && spanY > 0.0 -> min(usableWidth / spanX, usableHeight / spanY)
        spanX > 0.0 -> usableWidth / spanX
        spanY > 0.0 -> usableHeight / spanY
        else -> 0.0
    }

    val originX = (width - spanX * scale) / 2.0
    val originY = (height - spanY * scale) / 2.0
    return polylines.map { line ->
        line.map { vertex ->
            val offsetX = (vertex.longitude - minLongitude) * longitudeScale * scale
            val offsetY = (maxLatitude - vertex.latitude) * scale
            CanvasPoint((originX + offsetX).toFloat(), (originY + offsetY).toFloat())
        }
    }
}

fun minimumTraceMeters(target: PhotoTarget, edition: Edition): Double? =
    if (target != PhotoTarget.StreetsTraced) {
        null
    } else {
        when (edition) {
            Edition.Metric -> TRACE_MINIMUM_KILOMETRE_METERS
            Edition.Imperial -> TRACE_MINIMUM_HALF_MILE_METERS
        }
    }

data class CanvasPoint(val x: Float, val y: Float)

internal fun haversineMeters(from: ZonePin, to: ZonePin): Double {
    val dLat = (to.latitude - from.latitude) * DEG_TO_RAD
    val dLng = (to.longitude - from.longitude) * DEG_TO_RAD
    val a = sin(dLat / 2.0).let { it * it } +
        cos(from.latitude * DEG_TO_RAD) * cos(to.latitude * DEG_TO_RAD) *
        sin(dLng / 2.0).let { it * it }
    return EARTH_RADIUS_METERS * 2.0 * atan2(sqrt(a), sqrt(1.0 - a))
}

private const val EARTH_RADIUS_METERS = 6_371_000.0
private const val DEG_TO_RAD = PI / 180.0
private const val TRACE_MINIMUM_KILOMETRE_METERS = 1000.0
private const val TRACE_MINIMUM_HALF_MILE_METERS = 804.672
