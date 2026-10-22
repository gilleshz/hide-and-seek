@file:Suppress("MatchingDeclarationName")

package fr.gshz.hideandseek.feature.map

import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.sin

internal data class MarkerFanOffset(val x: Float, val y: Float) {
    companion object {
        val NONE = MarkerFanOffset(0f, 0f)
    }
}

/**
 * Markers within [thresholdMeters] of each other are spread around a ring of [radiusPx]
 * so their pins and initials no longer paint on top of each other.
 */
internal fun fanOffsets(
    markers: List<PlayerMarker>,
    thresholdMeters: Double = 15.0,
    radiusPx: Float = 20f,
): Map<String, MarkerFanOffset> {
    val offsets = markers.associate { it.playerUuid to MarkerFanOffset.NONE }.toMutableMap()
    val remaining = markers.sortedBy { it.playerUuid }.toMutableList()

    while (remaining.isNotEmpty()) {
        val group = mutableListOf(remaining.removeAt(0))
        val iterator = remaining.iterator()
        while (iterator.hasNext()) {
            val candidate = iterator.next()
            val nearGroup = group.any { member ->
                haversineMeters(member.latitude, member.longitude, candidate.latitude, candidate.longitude) <=
                    thresholdMeters
            }
            if (nearGroup) {
                group += candidate
                iterator.remove()
            }
        }
        if (group.size < 2) continue

        group.sortBy { it.playerUuid }
        group.forEachIndexed { index, marker ->
            val angle = -PI / 2.0 + 2.0 * PI * index / group.size
            offsets[marker.playerUuid] = MarkerFanOffset(
                x = (radiusPx.toDouble() * cos(angle)).toFloat(),
                y = (radiusPx.toDouble() * sin(angle)).toFloat(),
            )
        }
    }

    return offsets
}
