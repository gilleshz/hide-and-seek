package fr.gshz.hideandseek.core.util

import fr.gshz.hideandseek.domain.model.BoundingBox
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.doubleOrNull

private val GEOMETRY_TYPES = setOf(
    "Point",
    "MultiPoint",
    "LineString",
    "MultiLineString",
    "Polygon",
    "MultiPolygon",
)

/**
 * Bounding box of every finite position in a GeoJSON document, or null when it holds none.
 * GeoJSON positions are `[longitude, latitude]`.
 */
fun geoJsonBoundingBox(geoJson: String): BoundingBox? {
    val root = try {
        Json.parseToJsonElement(geoJson)
    } catch (_: IllegalArgumentException) {
        return null
    }
    val bounds = Bounds()
    collectElement(root, bounds)
    return bounds.toBoundingBox()
}

private class Bounds {
    private var swLat = Double.POSITIVE_INFINITY
    private var swLng = Double.POSITIVE_INFINITY
    private var neLat = Double.NEGATIVE_INFINITY
    private var neLng = Double.NEGATIVE_INFINITY

    fun add(lat: Double, lng: Double) {
        if (!lat.isFinite() || !lng.isFinite()) return
        if (lat < swLat) swLat = lat
        if (lng < swLng) swLng = lng
        if (lat > neLat) neLat = lat
        if (lng > neLng) neLng = lng
    }

    fun toBoundingBox(): BoundingBox? = if (swLat > neLat) {
        null
    } else {
        BoundingBox(swLat = swLat, swLng = swLng, neLat = neLat, neLng = neLng)
    }
}

private fun collectElement(element: JsonElement, bounds: Bounds) {
    val obj = element as? JsonObject ?: return
    when ((obj["type"] as? JsonPrimitive)?.content) {
        "FeatureCollection" -> (obj["features"] as? JsonArray)?.forEach { collectElement(it, bounds) }
        "Feature" -> obj["geometry"]?.let { collectElement(it, bounds) }
        "GeometryCollection" -> (obj["geometries"] as? JsonArray)?.forEach { collectElement(it, bounds) }
        in GEOMETRY_TYPES -> obj["coordinates"]?.let { collectCoordinates(it, bounds) }
    }
}

private fun collectCoordinates(element: JsonElement, bounds: Bounds) {
    val array = element as? JsonArray ?: return
    if (array.firstOrNull() !is JsonArray) {
        val lng = (array.getOrNull(0) as? JsonPrimitive)?.doubleOrNull
        val lat = (array.getOrNull(1) as? JsonPrimitive)?.doubleOrNull
        if (lng != null && lat != null) bounds.add(lat = lat, lng = lng)
        return
    }
    array.forEach { collectCoordinates(it, bounds) }
}
