@file:Suppress("TooManyFunctions")

package fr.gshz.hideandseek.feature.map

import android.graphics.PointF
import android.graphics.RectF
import fr.gshz.hideandseek.domain.model.ConstraintMode
import fr.gshz.hideandseek.domain.model.ManualConstraint
import org.json.JSONException
import org.json.JSONObject
import org.maplibre.android.maps.MapLibreMap
import org.maplibre.android.maps.Style
import org.maplibre.android.style.expressions.Expression
import org.maplibre.android.style.layers.CircleLayer
import org.maplibre.android.style.layers.FillLayer
import org.maplibre.android.style.layers.LineLayer
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.sources.GeoJsonSource

private const val DRAW_SOURCE_ID = "drawing-source"
private const val DRAW_FILL_LAYER_ID = "drawing-fill"
private const val DRAW_LINE_LAYER_ID = "drawing-line"
private const val DRAW_VERTEX_SOURCE_ID = "drawing-vertex-source"
private const val DRAW_VERTEX_LAYER_ID = "drawing-vertex"
private const val STREET_NETWORK_SOURCE_ID = "street-network-source"
private const val STREET_NETWORK_LINE_LAYER_ID = "street-network-line"

internal const val MANUAL_CONSTRAINT_FILL_LAYER_ID = "manual-constraint-fill"
private const val MANUAL_CONSTRAINT_SOURCE_ID = "manual-constraint-source"
private const val MANUAL_CONSTRAINT_LINE_LAYER_ID = "manual-constraint-line"

private const val COLOR_PROP = "color"
private const val UUID_PROP = "uuid"
private const val KIND_PROP = "kind"
private const val FILL_KIND = "fill"
private const val LINE_KIND = "line"

private const val DRAW_FILL_OPACITY = 0.25f
private const val DRAW_LINE_WIDTH = 2.5f
private const val STREET_NETWORK_COLOR = "#9CA3AF"
private const val STREET_NETWORK_LINE_WIDTH = 1.2f
private const val STREET_NETWORK_OPACITY = 0.5f
private const val DRAW_VERTEX_RADIUS = 7f
private const val DRAW_VERTEX_STROKE_WIDTH = 2f
private const val VERTEX_STROKE_COLOR = "#FFFFFF"
private const val MANUAL_FILL_OPACITY = 0.18f
private const val MANUAL_LINE_WIDTH = 2f
private val MANUAL_LINE_DASHES = arrayOf(3f, 2f)
private const val MIN_RING_VERTICES = 3
private const val MIN_LINE_VERTICES = 2
private const val EMPTY_COLLECTION = """{"type":"FeatureCollection","features":[]}"""

// Shade the complement (world minus the drawn ring) so the kept interior stays map-visible.
private const val WORLD_RING = "[[-180.0,-85.0],[180.0,-85.0],[180.0,85.0],[-180.0,85.0],[-180.0,-85.0]]"

private val FILL_FILTER = Expression.eq(Expression.get(KIND_PROP), Expression.literal(FILL_KIND))
private val LINE_FILTER = Expression.eq(Expression.get(KIND_PROP), Expression.literal(LINE_KIND))

internal fun Style.ensureManualConstraintLayer() {
    if (getSource(MANUAL_CONSTRAINT_SOURCE_ID) != null) return
    addSource(GeoJsonSource(MANUAL_CONSTRAINT_SOURCE_ID))
    addLayer(
        FillLayer(MANUAL_CONSTRAINT_FILL_LAYER_ID, MANUAL_CONSTRAINT_SOURCE_ID).withProperties(
            PropertyFactory.fillColor(Expression.get(COLOR_PROP)),
            PropertyFactory.fillOpacity(MANUAL_FILL_OPACITY),
        ).also { it.setFilter(FILL_FILTER) },
    )
    addLayer(
        LineLayer(MANUAL_CONSTRAINT_LINE_LAYER_ID, MANUAL_CONSTRAINT_SOURCE_ID).withProperties(
            PropertyFactory.lineColor(Expression.get(COLOR_PROP)),
            PropertyFactory.lineWidth(MANUAL_LINE_WIDTH),
            PropertyFactory.lineDasharray(MANUAL_LINE_DASHES),
        ).also { it.setFilter(LINE_FILTER) },
    )
}

internal fun Style.ensureDrawingLayer() {
    if (getSource(DRAW_SOURCE_ID) != null) return
    // Added first so the street network draws beneath the hider's selection.
    addSource(GeoJsonSource(STREET_NETWORK_SOURCE_ID))
    addLayer(
        LineLayer(STREET_NETWORK_LINE_LAYER_ID, STREET_NETWORK_SOURCE_ID).withProperties(
            PropertyFactory.lineColor(STREET_NETWORK_COLOR),
            PropertyFactory.lineWidth(STREET_NETWORK_LINE_WIDTH),
            PropertyFactory.lineOpacity(STREET_NETWORK_OPACITY),
        ),
    )
    addSource(GeoJsonSource(DRAW_SOURCE_ID))
    addLayer(
        FillLayer(DRAW_FILL_LAYER_ID, DRAW_SOURCE_ID).withProperties(
            PropertyFactory.fillColor(Expression.get(COLOR_PROP)),
            PropertyFactory.fillOpacity(DRAW_FILL_OPACITY),
        ).also { it.setFilter(FILL_FILTER) },
    )
    addLayer(
        LineLayer(DRAW_LINE_LAYER_ID, DRAW_SOURCE_ID).withProperties(
            PropertyFactory.lineColor(Expression.get(COLOR_PROP)),
            PropertyFactory.lineWidth(DRAW_LINE_WIDTH),
        ).also { it.setFilter(LINE_FILTER) },
    )
    addSource(GeoJsonSource(DRAW_VERTEX_SOURCE_ID))
    addLayer(
        CircleLayer(DRAW_VERTEX_LAYER_ID, DRAW_VERTEX_SOURCE_ID).withProperties(
            PropertyFactory.circleColor(Expression.get(COLOR_PROP)),
            PropertyFactory.circleRadius(DRAW_VERTEX_RADIUS),
            PropertyFactory.circleStrokeColor(VERTEX_STROKE_COLOR),
            PropertyFactory.circleStrokeWidth(DRAW_VERTEX_STROKE_WIDTH),
        ),
    )
}

internal fun Style.updateDrawingSource(
    vertices: List<ZonePin>,
    selectedPaths: List<List<ZonePin>>,
    networkPaths: List<List<ZonePin>>,
    mode: ConstraintMode,
    kind: DrawKind,
) {
    val color = when (kind) {
        DrawKind.Area -> MapConstants.CONSTRAINT_EXCLUDE_COLOR
        DrawKind.Trace -> MapConstants.TRACE_COLOR
    }
    val isTrace = kind == DrawKind.Trace
    (getSource(DRAW_SOURCE_ID) as? GeoJsonSource)
        ?.setGeoJson(drawingShapeGeoJson(vertices, selectedPaths, mode, kind, color))
    (getSource(DRAW_VERTEX_SOURCE_ID) as? GeoJsonSource)
        ?.setGeoJson(vertexPointsGeoJson(if (isTrace) emptyList() else vertices, color))
    (getSource(STREET_NETWORK_SOURCE_ID) as? GeoJsonSource)
        ?.setGeoJson(multiLineFeatureCollection(if (isTrace) networkPaths else emptyList(), STREET_NETWORK_COLOR))
}

internal fun Style.updateManualConstraintSource(constraints: List<ManualConstraint>) {
    (getSource(MANUAL_CONSTRAINT_SOURCE_ID) as? GeoJsonSource)?.setGeoJson(manualConstraintsGeoJson(constraints))
}

internal fun MapLibreMap.manualConstraintUuidAt(point: PointF, radiusPx: Float): String? {
    val rect = RectF(point.x - radiusPx, point.y - radiusPx, point.x + radiusPx, point.y + radiusPx)
    return queryRenderedFeatures(rect, MANUAL_CONSTRAINT_FILL_LAYER_ID)
        .firstOrNull()
        ?.getStringProperty(UUID_PROP)
        ?.takeIf { it.isNotEmpty() }
}

private fun drawingShapeGeoJson(
    vertices: List<ZonePin>,
    selectedPaths: List<List<ZonePin>>,
    mode: ConstraintMode,
    kind: DrawKind,
    color: String,
): String = when {
    // One line per chosen street, so a disjoint or branchy selection draws in full.
    kind == DrawKind.Trace -> multiLineFeatureCollection(selectedPaths, color)
    vertices.size < MIN_LINE_VERTICES -> EMPTY_COLLECTION
    else -> areaShapeGeoJson(vertices, mode, color)
}

private fun multiLineFeatureCollection(paths: List<List<ZonePin>>, color: String): String {
    val lines = paths.filter { it.size >= MIN_LINE_VERTICES }
    if (lines.isEmpty()) return EMPTY_COLLECTION
    val features = lines.joinToString(",") { feature(lineString(openCoords(it)), propsJson(color, LINE_KIND)) }
    return featureCollection(features)
}

private fun areaShapeGeoJson(vertices: List<ZonePin>, mode: ConstraintMode, color: String): String {
    val ring = ringCoords(vertices)
    val outline = feature(lineString(ring), propsJson(color, LINE_KIND))
    val features = if (vertices.size < MIN_RING_VERTICES) {
        outline
    } else {
        "${feature(fillPolygon(ring, mode), propsJson(color, FILL_KIND))},$outline"
    }
    return featureCollection(features)
}

private fun vertexPointsGeoJson(vertices: List<ZonePin>, color: String): String {
    if (vertices.isEmpty()) return EMPTY_COLLECTION
    val features = vertices.joinToString(",") {
        feature("""{"type":"Point","coordinates":[${it.longitude},${it.latitude}]}""", propsJson(color, ""))
    }
    return featureCollection(features)
}

private fun manualConstraintsGeoJson(constraints: List<ManualConstraint>): String {
    if (constraints.isEmpty()) return EMPTY_COLLECTION
    val features = constraints.mapNotNull { manualConstraintFeatures(it) }.joinToString(",")
    return featureCollection(features)
}

private fun manualConstraintFeatures(constraint: ManualConstraint): String? {
    val ring = extractRing(constraint.geoJson) ?: return null
    val color = MapConstants.CONSTRAINT_EXCLUDE_COLOR
    val fill = feature(fillPolygon(ring, constraint.mode), propsJson(color, FILL_KIND, constraint.uuid))
    val outline = feature(lineString(ring), propsJson(color, LINE_KIND, constraint.uuid))
    return "$fill,$outline"
}

private fun ringCoords(vertices: List<ZonePin>): String = openCoords(vertices + vertices.first())

private fun openCoords(vertices: List<ZonePin>): String =
    vertices.joinToString(",", prefix = "[", postfix = "]") { "[${it.longitude},${it.latitude}]" }

private fun fillPolygon(ring: String, mode: ConstraintMode): String = when (mode) {
    ConstraintMode.Exclude -> """{"type":"Polygon","coordinates":[$ring]}"""
    ConstraintMode.Include -> """{"type":"Polygon","coordinates":[$WORLD_RING,$ring]}"""
}

private fun lineString(ring: String): String = """{"type":"LineString","coordinates":$ring}"""

private fun feature(geometry: String, propsJson: String): String =
    """{"type":"Feature","geometry":$geometry,"properties":$propsJson}"""

private fun propsJson(color: String, kind: String, uuid: String? = null): String {
    val props = JSONObject().put(COLOR_PROP, color)
    if (kind.isNotEmpty()) props.put(KIND_PROP, kind)
    if (uuid != null) props.put(UUID_PROP, uuid)
    return props.toString()
}

private fun extractRing(geoJson: String): String? {
    val geometry = extractGeometry(geoJson) ?: return null
    val coordinates = geometry.optJSONArray("coordinates")
    val ring = when (geometry.optString("type")) {
        "Polygon" -> coordinates?.optJSONArray(0)
        "MultiPolygon" -> coordinates?.optJSONArray(0)?.optJSONArray(0)
        else -> null
    }
    return ring?.takeIf { it.length() >= MIN_RING_VERTICES }?.toString()
}

private fun extractGeometry(geoJson: String): JSONObject? =
    try {
        geometryOf(JSONObject(geoJson))
    } catch (_: JSONException) {
        null
    }

private fun geometryOf(root: JSONObject): JSONObject? = when (root.optString("type")) {
    "Polygon", "MultiPolygon" -> root
    "Feature" -> root.optJSONObject("geometry")
    "FeatureCollection" -> root.optJSONArray("features")?.optJSONObject(0)?.optJSONObject("geometry")
    else -> null
}

private fun featureCollection(features: String): String =
    """{"type":"FeatureCollection","features":[$features]}"""
