@file:Suppress("MatchingDeclarationName")
package fr.gshz.hideandseek.feature.map

import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import org.maplibre.android.maps.Style
import org.maplibre.android.style.expressions.Expression
import org.maplibre.android.style.layers.CircleLayer
import org.maplibre.android.style.layers.Property
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.layers.SymbolLayer
import org.maplibre.android.style.sources.GeoJsonSource

/**
 * What the map draws for a trap: never its accruing value, which ticks and would rebuild the GeoJSON
 * once a second; the figure rides on a Compose chip instead.
 */
internal data class TrapPin(
    val uuid: String,
    val latitude: Double,
    val longitude: Double,
    val label: String,
    val isPending: Boolean = false,
)

private val trapGeoJson = Json { encodeDefaults = true }

internal fun Style.ensureTimeTrapLayer() {
    if (getSource(TRAP_SOURCE_ID) != null) return

    addSource(GeoJsonSource(TRAP_SOURCE_ID))
    addSource(GeoJsonSource(TRAP_LABEL_SOURCE_ID))
    addLayer(
        CircleLayer(TRAP_CIRCLE_LAYER_ID, TRAP_SOURCE_ID).withProperties(
            PropertyFactory.circleRadius(TRAP_CIRCLE_RADIUS),
            PropertyFactory.circleColor(TRAP_CIRCLE_COLOR),
            PropertyFactory.circleOpacity(TRAP_CIRCLE_OPACITY),
            PropertyFactory.circleStrokeWidth(TRAP_STROKE_WIDTH),
            PropertyFactory.circleStrokeColor(TRAP_STROKE_COLOR),
        ),
    )
    addLayer(
        SymbolLayer(TRAP_LABEL_LAYER_ID, TRAP_LABEL_SOURCE_ID).withProperties(
            PropertyFactory.textField(Expression.get(TRAP_LABEL_PROPERTY)),
            PropertyFactory.textFont(TRAP_LABEL_FONT),
            PropertyFactory.textSize(TRAP_LABEL_SIZE),
            PropertyFactory.textOffset(arrayOf(0f, TRAP_LABEL_OFFSET_Y)),
            PropertyFactory.textAnchor(Property.TEXT_ANCHOR_TOP),
            PropertyFactory.textAllowOverlap(true),
            PropertyFactory.textColor(TRAP_LABEL_COLOR),
            PropertyFactory.textHaloColor(TRAP_LABEL_HALO_COLOR),
            PropertyFactory.textHaloWidth(TRAP_LABEL_HALO_WIDTH),
        ),
    )
}

internal fun Style.updateTimeTrapSource(pins: List<TrapPin>) {
    val json = trapPinsGeoJson(pins)
    (getSource(TRAP_SOURCE_ID) as? GeoJsonSource)?.setGeoJson(json)
    (getSource(TRAP_LABEL_SOURCE_ID) as? GeoJsonSource)?.setGeoJson(json)
}

private fun trapPinsGeoJson(pins: List<TrapPin>): String = trapGeoJson.encodeToString(
    TrapFeatureCollection(
        features = pins.map { pin ->
            TrapFeature(
                geometry = TrapGeometry(coordinates = listOf(pin.longitude, pin.latitude)),
                properties = TrapProperties(uuid = pin.uuid, label = pin.label, pending = pin.isPending),
            )
        },
    ),
)

@Serializable
private data class TrapFeatureCollection(
    val type: String = "FeatureCollection",
    val features: List<TrapFeature>,
)

@Serializable
private data class TrapFeature(
    val type: String = "Feature",
    val geometry: TrapGeometry,
    val properties: TrapProperties,
)

@Serializable
private data class TrapGeometry(
    val type: String = "Point",
    val coordinates: List<Double>,
)

@Serializable
private data class TrapProperties(
    val uuid: String,
    val label: String,
    val pending: Boolean,
)

private const val TRAP_SOURCE_ID = "time-trap-source"
private const val TRAP_LABEL_SOURCE_ID = "time-trap-label-source"
private const val TRAP_CIRCLE_LAYER_ID = "time-trap-circle"
private const val TRAP_LABEL_LAYER_ID = "time-trap-label"
private const val TRAP_LABEL_PROPERTY = "label"
private val TRAP_LABEL_FONT = arrayOf("Noto Sans Regular")
private const val TRAP_CIRCLE_COLOR = "#7C3AED"
private const val TRAP_CIRCLE_RADIUS = 10f
private const val TRAP_CIRCLE_OPACITY = 0.85f
private const val TRAP_STROKE_COLOR = "#FFFFFF"
private const val TRAP_STROKE_WIDTH = 2.5f
private const val TRAP_LABEL_SIZE = 11f
private const val TRAP_LABEL_OFFSET_Y = 1.4f
private const val TRAP_LABEL_COLOR = "#FFFFFF"
private const val TRAP_LABEL_HALO_COLOR = "#000000"
private const val TRAP_LABEL_HALO_WIDTH = 1.5f
