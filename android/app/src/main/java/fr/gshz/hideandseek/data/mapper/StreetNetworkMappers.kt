package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.StreetNetworkDto
import fr.gshz.hideandseek.data.remote.dto.StreetWayDto
import fr.gshz.hideandseek.domain.model.StreetClass
import fr.gshz.hideandseek.domain.model.StreetNetwork
import fr.gshz.hideandseek.domain.model.StreetNetworkStatus
import fr.gshz.hideandseek.domain.model.StreetPoint
import fr.gshz.hideandseek.domain.model.StreetWay

fun StreetNetworkDto.toDomain() = StreetNetwork(
    status = StreetNetworkStatus.fromWireValueOrNull(status) ?: StreetNetworkStatus.Unavailable,
    ways = ways.mapNotNull { it.toDomainOrNull() },
)

/**
 * A single bad pair discards the whole way: dropping it instead would renumber every junction index
 * above it, and a one-tap answer would then run to the wrong intersection with nothing to show for it.
 */
private fun StreetWayDto.toDomainOrNull(): StreetWay? {
    val points = coordinates.map { it.toStreetPointOrNull() ?: return null }
    return if (points.size < MIN_WAY_POINTS) {
        null
    } else {
        StreetWay(
            streetClass = StreetClass.fromWireValueOrNull(streetClass) ?: StreetClass.Other,
            points = points,
            junctionIndices = junctionIndices.filter { it in points.indices },
        )
    }
}

private fun List<Double>.toStreetPointOrNull(): StreetPoint? {
    if (size < COORDINATE_PAIR_SIZE) return null
    val latitude = this[1]
    val longitude = this[0]
    // NaN and infinity fall outside both ranges, so this covers every non-finite pair too.
    val onEarth = latitude in MIN_LATITUDE..MAX_LATITUDE && longitude in MIN_LONGITUDE..MAX_LONGITUDE
    return if (onEarth) StreetPoint(latitude = latitude, longitude = longitude) else null
}

private const val MIN_WAY_POINTS = 2
private const val COORDINATE_PAIR_SIZE = 2
private const val MIN_LATITUDE = -90.0
private const val MAX_LATITUDE = 90.0
private const val MIN_LONGITUDE = -180.0
private const val MAX_LONGITUDE = 180.0
