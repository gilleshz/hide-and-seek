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
internal const val STATION_FILL_LAYER_ID = "transit-overlay-stations"

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

private fun Style.firstSymbolLayerId(): String? = layers.firstOrNull { it is SymbolLayer }?.id

internal fun Style.ensureTransitOverlayLayer(overlayGeoJson: String) {
    if (getSource(TRANSIT_OVERLAY_SOURCE_ID) != null) return
    addSource(GeoJsonSource(TRANSIT_OVERLAY_SOURCE_ID, overlayGeoJson))

    // Insertion order is the draw order: casing under fill, stations over both.
    val ordered = listOf(
        lineLayer("transit-overlay-casing", "butt"),
        lineLayer("transit-overlay-fill", "round"),
        stationLayer(STATION_FILL_LAYER_ID),
        stationBorderLayer("transit-overlay-stations-border"),
    )
    val labelId = firstSymbolLayerId()
    ordered.forEach { if (labelId != null) addLayerBelow(it, labelId) else addLayer(it) }
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
