package fr.gshz.hideandseek.feature.map

import org.maplibre.android.maps.Style
import org.maplibre.android.style.expressions.Expression
import org.maplibre.android.style.layers.FillLayer
import org.maplibre.android.style.layers.LineLayer
import org.maplibre.android.style.layers.Property
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.layers.SymbolLayer
import org.maplibre.android.style.sources.GeoJsonSource

private const val TRANSIT_OVERLAY_SOURCE_ID = "transit-overlay-source"
private const val CASING_LAYER_ID = "transit-overlay-casing"
private const val FILL_LAYER_ID = "transit-overlay-fill"
internal const val STATION_FILL_LAYER_ID = "transit-overlay-stations"
private const val STATION_BORDER_LAYER_ID = "transit-overlay-stations-border"
private const val FOCUS_CASING_LAYER_ID = "transit-overlay-focus-casing"
private const val FOCUS_FILL_LAYER_ID = "transit-overlay-focus"

private const val BASE_ZOOM = 14f

// Lines sit adjacent (the LOOM bundle offset), with the black casing slightly wider so it reads as
// a thin separator; widths are screen-relative below z14 and grow with zoom above it.
private const val FILL_WIDTH_Z14 = 2.6f
private const val CASING_WIDTH_Z14 = 3.75f
private const val STATION_BORDER_WIDTH_Z14 = 1.25f
private const val ZOOM_EXP_BASE = 2f
private const val OVERZOOM_MID = 18f
private const val OVERZOOM_MID_FACTOR = 3f
private const val OVERZOOM_MAX = 22f
private const val OVERZOOM_MAX_FACTOR = 6f

// Fade rather than filter: black casings carry no ref, and same-coloured lines are the case served.
private const val DIMMED_LINE_OPACITY = 0.12f
private const val FOCUS_HALO_OPACITY = 0.85f
private const val FOCUS_WIDTH_FACTOR = 1.8f
private const val FOCUS_HALO_FACTOR = 2f

private val FOCUS_HALO_COLOR: Expression = Expression.toColor(Expression.literal("#FFFFFF"))

private fun Style.firstSymbolLayerId(): String? = layers.firstOrNull { it is SymbolLayer }?.id

internal fun Style.ensureTransitOverlayLayer(overlayGeoJson: String, focusedRef: String? = null) {
    if (getSource(TRANSIT_OVERLAY_SOURCE_ID) != null) return
    addSource(GeoJsonSource(TRANSIT_OVERLAY_SOURCE_ID, overlayGeoJson))

    // Insertion order is the draw order: casing under fill, stations over both.
    val ordered = listOf(
        lineLayer(CASING_LAYER_ID, "butt"),
        lineLayer(FILL_LAYER_ID, "round"),
        stationLayer(STATION_FILL_LAYER_ID),
        stationBorderLayer(STATION_BORDER_LAYER_ID),
    )
    val labelId = firstSymbolLayerId()
    ordered.forEach { if (labelId != null) addLayerBelow(it, labelId) else addLayer(it) }

    val haloWidth = CASING_WIDTH_Z14 * FOCUS_HALO_FACTOR
    val halo = focusLayer(FOCUS_CASING_LAYER_ID, FOCUS_HALO_COLOR, haloWidth, FOCUS_HALO_OPACITY)
    val route = focusLayer(FOCUS_FILL_LAYER_ID, overlayColor("lineColor"), FILL_WIDTH_Z14 * FOCUS_WIDTH_FACTOR)
    // Under the stations, as the base lines are, so the focused route never covers a stop marker.
    addLayerAbove(halo, FILL_LAYER_ID)
    addLayerAbove(route, FOCUS_CASING_LAYER_ID)
    applyTransitFocus(focusedRef)
}

internal fun Style.applyTransitFocus(ref: String?) {
    val dimmed = ref != null
    setLineOpacity(CASING_LAYER_ID, if (dimmed) DIMMED_LINE_OPACITY else 1f)
    setLineOpacity(FILL_LAYER_ID, if (dimmed) DIMMED_LINE_OPACITY else 1f)
    // Only fill features carry a ref, so filtering by it never touches the other lines' casings.
    val filter = Expression.eq(Expression.get("ref"), Expression.literal(ref ?: ""))
    getLayerAs<LineLayer>(FOCUS_CASING_LAYER_ID)?.setFilter(filter)
    getLayerAs<LineLayer>(FOCUS_FILL_LAYER_ID)?.setFilter(filter)
}

private fun Style.setLineOpacity(id: String, opacity: Float) {
    getLayerAs<LineLayer>(id)?.setProperties(PropertyFactory.lineOpacity(opacity))
}

private fun focusLayer(id: String, color: Expression, width: Float, opacity: Float = 1f): LineLayer =
    LineLayer(id, TRANSIT_OVERLAY_SOURCE_ID).apply {
        setFilter(Expression.eq(Expression.get("ref"), Expression.literal("")))
        setProperties(
            PropertyFactory.lineColor(color),
            PropertyFactory.lineWidth(zoomWidth(width)),
            PropertyFactory.lineCap(Property.LINE_CAP_ROUND),
            PropertyFactory.lineJoin(Property.LINE_JOIN_ROUND),
            PropertyFactory.lineOpacity(opacity),
        )
    }

private fun lineLayer(id: String, cap: String): LineLayer {
    val isFill = cap == "round"
    return LineLayer(id, TRANSIT_OVERLAY_SOURCE_ID).apply {
        setFilter(Expression.eq(Expression.get("lineCap"), Expression.literal(cap)))
        setProperties(
            PropertyFactory.lineColor(overlayColor("color")),
            PropertyFactory.lineWidth(zoomWidth(if (isFill) FILL_WIDTH_Z14 else CASING_WIDTH_Z14)),
            PropertyFactory.lineCap(if (isFill) Property.LINE_CAP_ROUND else Property.LINE_CAP_BUTT),
            PropertyFactory.lineJoin(Property.LINE_JOIN_ROUND),
        )
    }
}

private fun stationLayer(id: String): FillLayer =
    FillLayer(id, TRANSIT_OVERLAY_SOURCE_ID).apply {
        setFilter(isStation())
        setProperties(PropertyFactory.fillColor(overlayColor("fillColor")))
    }

private fun stationBorderLayer(id: String): LineLayer =
    LineLayer(id, TRANSIT_OVERLAY_SOURCE_ID).apply {
        setFilter(isStation())
        setProperties(
            PropertyFactory.lineColor(overlayColor("color")),
            PropertyFactory.lineWidth(zoomWidth(STATION_BORDER_WIDTH_Z14)),
            PropertyFactory.lineJoin(Property.LINE_JOIN_ROUND),
        )
    }

private fun isStation(): Expression =
    Expression.eq(Expression.geometryType(), Expression.literal("Polygon"))

private fun overlayColor(property: String): Expression =
    Expression.toColor(Expression.concat(Expression.literal("#"), Expression.get(property)))

private fun zoomWidth(base: Float): Expression =
    Expression.interpolate(
        Expression.exponential(ZOOM_EXP_BASE),
        Expression.zoom(),
        Expression.stop(BASE_ZOOM, base),
        Expression.stop(OVERZOOM_MID, base * OVERZOOM_MID_FACTOR),
        Expression.stop(OVERZOOM_MAX, base * OVERZOOM_MAX_FACTOR),
    )
