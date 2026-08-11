package fr.gshz.hideandseek.feature.map

import kotlin.math.PI
import kotlin.math.abs
import kotlin.math.asin
import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.sin
import kotlin.math.sqrt

private const val EARTH_RADIUS_M = 6_371_008.8
private const val CIRCLE_POINTS = 64
private const val BISECTOR_HALF_KM = 50.0
private const val BISECTOR_MIN_LENGTH = 1e-9
private const val DEG_TO_RAD = PI / 180.0
private const val RAD_TO_DEG = 180.0 / PI
private const val CIRCLE_TWO_PI = 2.0 * PI

/**
 * Geodesic circle polygon around [centerLat]/[centerLng] as a GeoJSON
 * FeatureCollection. The ring closes on the start point so the polygon is valid.
 */
internal fun circlePolygonGeoJson(centerLat: Double, centerLng: Double, radiusMeters: Double): String {
    val coords = circlePolygonCoords(centerLat, centerLng, radiusMeters)
    val ring = (coords + coords.first())
        .joinToString(",", prefix = "[", postfix = "]") { "[${it.first},${it.second}]" }
    return """{"type":"FeatureCollection","features":[""" +
        """{"type":"Feature","geometry":{"type":"Polygon","coordinates":[$ring]},"properties":{}}]}"""
}

/**
 * Bare GeoJSON Polygon (closed linear ring) from [vertices]. The backend's
 * ST_GeomFromGeoJSON expects a geometry object, not a Feature.
 */
internal fun polygonRingGeoJson(vertices: List<ZonePin>): String {
    val ring = (vertices + vertices.first())
        .joinToString(",", prefix = "[", postfix = "]") { "[${it.longitude},${it.latitude}]" }
    return """{"type":"Polygon","coordinates":[$ring]}"""
}

/**
 * Perpendicular bisector of the segment from (startLat, startLng) to (endLat, endLng),
 * extended [BISECTOR_HALF_KM] km from the midpoint in each direction.
 */
internal fun bisectorLineGeoJson(
    startLat: Double,
    startLng: Double,
    endLat: Double,
    endLng: Double,
): String {
    val midLat = (startLat + endLat) / 2.0
    val midLng = (startLng + endLng) / 2.0
    val dx = endLng - startLng
    val dy = endLat - startLat
    if (abs(dx) < BISECTOR_MIN_LENGTH && abs(dy) < BISECTOR_MIN_LENGTH) {
        return """{"type":"FeatureCollection","features":[]}"""
    }

    val mPerDegLat = EARTH_RADIUS_M * DEG_TO_RAD
    val mPerDegLng = mPerDegLat * cos(midLat * DEG_TO_RAD)

    // 1° lon ≠ 1° lat in meters, so project to meter space before rotating.
    val dxM = dx * mPerDegLng
    val dyM = dy * mPerDegLat
    val lengthM = sqrt(dxM * dxM + dyM * dyM)

    val perpDxM = -dyM / lengthM
    val perpDyM = dxM / lengthM

    val km = BISECTOR_HALF_KM
    val x1 = midLng + perpDxM * km / mPerDegLng
    val y1 = midLat + perpDyM * km / mPerDegLat
    val x2 = midLng - perpDxM * km / mPerDegLng
    val y2 = midLat - perpDyM * km / mPerDegLat
    val line = "[[$x1,$y1],[$x2,$y2]]"
    return """{"type":"FeatureCollection","features":[""" +
        """{"type":"Feature","geometry":{"type":"LineString","coordinates":$line},"properties":{}}]}"""
}

/**
 * Complement of the geodesic disk within the bounding box: the disk is a hole in
 * the rectangle so the fill draws everything outside the disk.
 */
internal fun circleComplementGeoJson(
    centerLat: Double,
    centerLng: Double,
    radiusMeters: Double,
    bounds: MapBounds,
): String {
    val diskRing = circlePolygonCoords(centerLat, centerLng, radiusMeters)
    val diskCoords = (diskRing + diskRing.first())
        .joinToString(",", prefix = "[", postfix = "]") { "[${it.first},${it.second}]" }
    val bboxRing = "[[${bounds.swLng},${bounds.swLat}]," +
        "[${bounds.neLng},${bounds.swLat}]," +
        "[${bounds.neLng},${bounds.neLat}]," +
        "[${bounds.swLng},${bounds.neLat}]," +
        "[${bounds.swLng},${bounds.swLat}]]"
    return """{"type":"FeatureCollection","features":[""" +
        """{"type":"Feature","geometry":{"type":"Polygon","coordinates":[$bboxRing,$diskCoords]},"properties":{}}]}"""
}

private fun circlePolygonCoords(
    lat: Double,
    lng: Double,
    radiusMeters: Double,
    numPoints: Int = CIRCLE_POINTS,
): List<Pair<Double, Double>> {
    val latRad = lat * DEG_TO_RAD
    val lngRad = lng * DEG_TO_RAD
    val angularRadius = radiusMeters / EARTH_RADIUS_M

    return (0 until numPoints).map { i ->
        val angle = CIRCLE_TWO_PI * i / numPoints
        val sinAngRad = sin(angularRadius)
        val cosAngRad = cos(angularRadius)
        val pointLat = sin(latRad) * cosAngRad + cos(latRad) * sinAngRad * cos(angle)
        val pointLatRad = asin(pointLat)
        val pointLngRad = lngRad + atan2(
            sin(angle) * sinAngRad * cos(latRad),
            cosAngRad - sin(latRad) * sin(pointLatRad),
        )
        (pointLngRad * RAD_TO_DEG) to (pointLatRad * RAD_TO_DEG)
    }
}
