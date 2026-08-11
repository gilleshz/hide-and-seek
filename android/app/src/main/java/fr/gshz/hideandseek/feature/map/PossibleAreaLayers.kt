package fr.gshz.hideandseek.feature.map

import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject
import org.maplibre.android.maps.Style
import org.maplibre.android.style.layers.FillLayer
import org.maplibre.android.style.layers.LineLayer
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.sources.GeoJsonSource

private const val EXCLUSION_SOURCE_ID = "exclusion-zone-source"
private const val EXCLUSION_FILL_LAYER_ID = "exclusion-zone-fill"
private const val EXCLUSION_FILL_COLOR = "#1A1C2E"
private const val EXCLUSION_FILL_OPACITY = 0.45f

private const val POSSIBLE_AREA_SOURCE_ID = "possible-area-outline-source"
private const val POSSIBLE_AREA_LINE_LAYER_ID = "possible-area-outline-line"

// Same blue as the boundary border, so the possible area reads as the boundary shrinking.
private const val POSSIBLE_AREA_LINE_COLOR = "#3B82F6"
private const val POSSIBLE_AREA_LINE_WIDTH = 2f

internal fun Style.ensureExclusionLayer() {
    if (getSource(EXCLUSION_SOURCE_ID) != null) return
    addSource(GeoJsonSource(EXCLUSION_SOURCE_ID))
    addLayer(
        FillLayer(EXCLUSION_FILL_LAYER_ID, EXCLUSION_SOURCE_ID).withProperties(
            PropertyFactory.fillColor(EXCLUSION_FILL_COLOR),
            PropertyFactory.fillOpacity(EXCLUSION_FILL_OPACITY),
        ),
    )
    addSource(GeoJsonSource(POSSIBLE_AREA_SOURCE_ID))
    addLayer(
        LineLayer(POSSIBLE_AREA_LINE_LAYER_ID, POSSIBLE_AREA_SOURCE_ID).withProperties(
            PropertyFactory.lineColor(POSSIBLE_AREA_LINE_COLOR),
            PropertyFactory.lineWidth(POSSIBLE_AREA_LINE_WIDTH),
        ),
    )
}

/**
 * Inverted mask: a world-spanning polygon with the play area punched out as holes,
 * dimming everything outside it. Built from [possibleAreaGeoJson] so it narrows as
 * questions are answered, falling back to [boundaryGeoJson] before any.
 */
internal fun Style.updateExclusionSource(possibleAreaGeoJson: String?, boundaryGeoJson: String?) {
    val source = getSource(EXCLUSION_SOURCE_ID) as? GeoJsonSource ?: return
    source.setGeoJson(worldMaskGeoJson(possibleAreaGeoJson ?: boundaryGeoJson) ?: EMPTY_FEATURE_COLLECTION)

    val outlineSource = getSource(POSSIBLE_AREA_SOURCE_ID) as? GeoJsonSource ?: return
    outlineSource.setGeoJson(
        possibleAreaGeoJson?.let { asFeatureCollection(it) } ?: EMPTY_FEATURE_COLLECTION,
    )
}

private fun asFeatureCollection(geometry: String): String =
    "{\"type\":\"FeatureCollection\",\"features\":" +
        "[{\"type\":\"Feature\",\"geometry\":$geometry,\"properties\":{}}]}"

/**
 * World-spanning mask dimming everything outside [playAreaGeoJson]. Holes in the
 * play area (e.g. a radar "outside" donut) are re-dimmed as separate islands.
 * Returns null when no exterior rings can be extracted.
 */
internal fun worldMaskGeoJson(playAreaGeoJson: String?): String? {
    val allRings = playAreaGeoJson?.takeIf { it.isNotBlank() }?.let(::parseAllRings)
    if (allRings == null || allRings.exterior.isEmpty()) return null

    val islands = allRings.interior.map { ring ->
        JSONObject().put("type", "Polygon").put("coordinates", JSONArray().put(ring))
    }

    val worldMaskRings = JSONArray().put(worldRing())
    allRings.exterior.forEach(worldMaskRings::put)
    val worldMask = JSONObject().put("type", "Polygon").put("coordinates", worldMaskRings)

    return if (islands.isEmpty()) {
        asFeatureCollection(worldMask.toString())
    } else {
        // Emit each polygon as a separate Feature so MapLibre fills them all.
        val features = JSONArray()
        features.put(JSONObject()
            .put("type", "Feature")
            .put("geometry", worldMask)
            .put("properties", JSONObject()))
        islands.forEach { island ->
            features.put(JSONObject()
                .put("type", "Feature")
                .put("geometry", island)
                .put("properties", JSONObject()))
        }
        "{\"type\":\"FeatureCollection\",\"features\":$features}"
    }
}

/**
 * True when [geoJson] is valid JSON but has no rings (empty geometry). Such a value
 * means "no possible area", so the full game boundary stays visible instead of being
 * hidden behind an empty outline. Invalid JSON returns false.
 */
internal fun isEmptyGeoJsonGeometry(geoJson: String): Boolean {
    val rings = parseAllRings(geoJson) ?: return false
    return rings.exterior.isEmpty() && rings.interior.isEmpty()
}

private data class RingSet(val exterior: List<JSONArray>, val interior: List<JSONArray>)

private fun parseAllRings(geoJson: String): RingSet? =
    try {
        allRings(JSONObject(geoJson))
    } catch (_: JSONException) {
        null
    }

private fun allRings(root: JSONObject): RingSet {
    val exterior = mutableListOf<JSONArray>()
    val interior = mutableListOf<JSONArray>()
    when (root.optString("type")) {
        "FeatureCollection" -> {
            val features = root.optJSONArray("features") ?: JSONArray()
            for (i in 0 until features.length()) {
                features.optJSONObject(i)?.optJSONObject("geometry")?.let {
                    partitionRings(it, exterior, interior)
                }
            }
        }
        "Feature" -> root.optJSONObject("geometry")?.let { partitionRings(it, exterior, interior) }
        else -> partitionRings(root, exterior, interior)
    }
    return RingSet(exterior, interior)
}

private fun partitionRings(geometry: JSONObject, exterior: MutableList<JSONArray>, interior: MutableList<JSONArray>) {
    val coords = geometry.optJSONArray("coordinates") ?: return
    when (geometry.optString("type")) {
        "Polygon" -> partitionPolygonRings(coords, exterior, interior)
        "MultiPolygon" -> for (i in 0 until coords.length()) {
            coords.optJSONArray(i)?.let { partitionPolygonRings(it, exterior, interior) }
        }
    }
}

private fun partitionPolygonRings(
    rings: JSONArray,
    exterior: MutableList<JSONArray>,
    interior: MutableList<JSONArray>,
) {
    for (i in 0 until rings.length()) {
        val ring = rings.optJSONArray(i) ?: continue
        (if (i == 0) exterior else interior).add(ring)
    }
}

private fun worldRing(): JSONArray = JSONArray().apply {
    put(corner(-WORLD_LNG_LIMIT, WORLD_LAT_LIMIT))
    put(corner(WORLD_LNG_LIMIT, WORLD_LAT_LIMIT))
    put(corner(WORLD_LNG_LIMIT, -WORLD_LAT_LIMIT))
    put(corner(-WORLD_LNG_LIMIT, -WORLD_LAT_LIMIT))
    put(corner(-WORLD_LNG_LIMIT, WORLD_LAT_LIMIT))
}

private fun corner(lng: Double, lat: Double): JSONArray = JSONArray().put(lng).put(lat)

private const val WORLD_LNG_LIMIT = 180.0
private const val WORLD_LAT_LIMIT = 85.0
private const val EMPTY_FEATURE_COLLECTION = "{\"type\":\"FeatureCollection\",\"features\":[]}"
