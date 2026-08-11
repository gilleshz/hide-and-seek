@file:Suppress("TooManyFunctions")
package fr.gshz.hideandseek.feature.map

import androidx.compose.runtime.MutableState
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import org.maplibre.android.camera.CameraUpdateFactory
import org.maplibre.android.geometry.LatLng
import org.maplibre.android.geometry.LatLngBounds
import org.maplibre.android.maps.MapLibreMap
import org.maplibre.android.maps.Style
import org.maplibre.android.style.expressions.Expression
import org.maplibre.android.style.layers.CircleLayer
import org.maplibre.android.style.layers.FillLayer
import org.maplibre.android.style.layers.LineLayer
import org.maplibre.android.style.layers.Property
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.layers.SymbolLayer
import org.maplibre.android.style.sources.GeoJsonSource
import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.sin

private val geoJson = Json { encodeDefaults = true }

/**
 * Marker circles live on their own symbol-free sources so they always paint. The text
 * (initials/name) lives on separate label sources: a SymbolLayer whose glyphs fail to load
 * breaks the *rendering of every layer on its source*, so keeping text off the circle source
 * guarantees the pin shows even if glyphs are unavailable.
 */
internal fun Style.ensureMarkerLayers() {
    if (getSource(SELF_SOURCE_ID) != null) return

    addSource(GeoJsonSource(SELF_SOURCE_ID))
    addSource(GeoJsonSource(OTHERS_SOURCE_ID))
    addSource(GeoJsonSource(SELF_LABEL_SOURCE_ID))
    addSource(GeoJsonSource(OTHERS_LABEL_SOURCE_ID))
    addLayer(circleLayer(SELF_CIRCLE_LAYER_ID, SELF_SOURCE_ID, SELF_MARKER_COLOR, SELF_MARKER_RADIUS))
    addLayer(circleLayer(OTHERS_CIRCLE_LAYER_ID, OTHERS_SOURCE_ID, OTHER_MARKER_COLOR, CIRCLE_RADIUS))
    addLayer(initialsLayer(SELF_INITIALS_LAYER_ID, SELF_LABEL_SOURCE_ID))
    addLayer(labelLayer(SELF_LABEL_LAYER_ID, SELF_LABEL_SOURCE_ID))
    addLayer(initialsLayer(OTHERS_INITIALS_LAYER_ID, OTHERS_LABEL_SOURCE_ID))
    addLayer(labelLayer(OTHERS_LABEL_LAYER_ID, OTHERS_LABEL_SOURCE_ID))
}

internal fun Style.updateMarkerSources(markers: List<PlayerMarker>) {
    val (self, others) = markers.partition { it.isSelf }
    val selfJson = toFeatureCollectionJson(self)
    val othersJson = toFeatureCollectionJson(others)
    (getSource(SELF_SOURCE_ID) as? GeoJsonSource)?.setGeoJson(selfJson)
    (getSource(SELF_LABEL_SOURCE_ID) as? GeoJsonSource)?.setGeoJson(selfJson)
    (getSource(OTHERS_SOURCE_ID) as? GeoJsonSource)?.setGeoJson(othersJson)
    (getSource(OTHERS_LABEL_SOURCE_ID) as? GeoJsonSource)?.setGeoJson(othersJson)
}

internal fun Style.ensureZoneLayer() {
    if (getSource(ZONE_SOURCE_ID) != null) return

    addSource(GeoJsonSource(ZONE_SOURCE_ID))
    addLayer(
        FillLayer(ZONE_FILL_LAYER_ID, ZONE_SOURCE_ID).withProperties(
            PropertyFactory.fillColor(ZONE_FILL_COLOR),
            PropertyFactory.fillOpacity(ZONE_FILL_OPACITY),
        ),
    )
    addLayer(
        LineLayer(ZONE_LINE_LAYER_ID, ZONE_SOURCE_ID).withProperties(
            PropertyFactory.lineColor(ZONE_LINE_COLOR),
            PropertyFactory.lineWidth(ZONE_LINE_WIDTH),
        ),
    )
}

internal fun Style.updateZoneSource(pin: ZonePin?, radiusMeters: Double) {
    val geojson = if (pin == null) {
        EMPTY_ZONE_GEOJSON
    } else {
        zoneGeoJson(pin.latitude, pin.longitude, radiusMeters)
    }
    (getSource(ZONE_SOURCE_ID) as? GeoJsonSource)?.setGeoJson(geojson)
}

private fun zoneGeoJson(lat: Double, lng: Double, radiusMeters: Double): String {
    val coords = circlePolygonCoords(lat, lng, radiusMeters)
    val ring = (coords + coords.first())
        .joinToString(",", prefix = "[", postfix = "]") { "[${it.first},${it.second}]" }
    return """{"type":"FeatureCollection","features":[""" +
        """{"type":"Feature","geometry":{"type":"Polygon","coordinates":[$ring]},"properties":{}}]}"""
}

private fun circlePolygonCoords(
    lat: Double,
    lng: Double,
    radiusMeters: Double,
    numPoints: Int = CIRCLE_POLYGON_POINTS,
): List<Pair<Double, Double>> {
    val latRad = lat * DEG_TO_RAD
    val lngRad = lng * DEG_TO_RAD
    val angularRadius = radiusMeters / EARTH_RADIUS_METERS

    return (0 until numPoints).map { i ->
        val angle = CIRCLE_TWO_PI * i / numPoints
        val sinAngRad = sin(angularRadius)
        val cosAngRad = cos(angularRadius)
        val pointLat = sin(latRad) * cosAngRad + cos(latRad) * sinAngRad * cos(angle)
        val pointLatRad = kotlin.math.asin(pointLat)
        val pointLngRad = lngRad + kotlin.math.atan2(
            sin(angle) * sinAngRad * cos(latRad),
            cosAngRad - sin(latRad) * sin(pointLatRad),
        )
        (pointLngRad * RAD_TO_DEG) to (pointLatRad * RAD_TO_DEG)
    }
}

internal fun Style.ensureSeekerMarkerLayer() {
    if (getSource(SEEKER_MARKER_SOURCE_ID) != null) return

    addSource(GeoJsonSource(SEEKER_MARKER_SOURCE_ID))
    addLayer(
        FillLayer(SEEKER_MARKER_FILL_LAYER_ID, SEEKER_MARKER_SOURCE_ID).withProperties(
            PropertyFactory.fillColor(ZONE_FILL_COLOR),
            PropertyFactory.fillOpacity(SEEKER_MARKER_FILL_OPACITY),
        ),
    )
    addLayer(
        LineLayer(SEEKER_MARKER_LINE_LAYER_ID, SEEKER_MARKER_SOURCE_ID).withProperties(
            PropertyFactory.lineColor(ZONE_LINE_COLOR),
            PropertyFactory.lineWidth(SEEKER_MARKER_LINE_WIDTH),
            PropertyFactory.lineDasharray(SEEKER_MARKER_LINE_DASHES),
        ),
    )
}

internal fun Style.updateSeekerMarkerSource(circles: List<ZonePin>, radiusMeters: Double) {
    val source = getSource(SEEKER_MARKER_SOURCE_ID) as? GeoJsonSource ?: return
    source.setGeoJson(seekerMarkersGeoJson(circles, radiusMeters))
}

private fun seekerMarkersGeoJson(circles: List<ZonePin>, radiusMeters: Double): String {
    if (circles.isEmpty()) return EMPTY_ZONE_GEOJSON
    val features = circles.joinToString(",") { pin ->
        val coords = circlePolygonCoords(pin.latitude, pin.longitude, radiusMeters)
        val ring = (coords + coords.first())
            .joinToString(",", prefix = "[", postfix = "]") { "[${it.first},${it.second}]" }
        """{"type":"Feature","geometry":{"type":"Polygon","coordinates":[$ring]},"properties":{}}"""
    }
    return """{"type":"FeatureCollection","features":[$features]}"""
}

internal fun MapLibreMap.animateRecenter(
    markers: List<PlayerMarker>,
    hasCenteredOnSelf: MutableState<Boolean>,
) {
    val self = markers.firstOrNull { it.isSelf } ?: return
    animateCamera(
        CameraUpdateFactory.newLatLngZoom(LatLng(self.latitude, self.longitude), MapConstants.DEFAULT_ZOOM),
    )
    hasCenteredOnSelf.value = true
}

internal fun MapLibreMap.frameCameraIfNeeded(
    markers: List<PlayerMarker>,
    boundary: MapBounds?,
    hasCenteredOnSelf: MutableState<Boolean>,
    hasFramedArea: MutableState<Boolean>,
) {
    if (hasCenteredOnSelf.value) return
    val self = markers.firstOrNull { it.isSelf }
    if (self != null) {
        easeCamera(CameraUpdateFactory.newLatLngZoom(LatLng(self.latitude, self.longitude), MapConstants.DEFAULT_ZOOM))
        hasCenteredOnSelf.value = true
        return
    }
    if (boundary != null && !hasFramedArea.value) {
        val bounds = LatLngBounds.Builder()
            .include(LatLng(boundary.neLat, boundary.neLng))
            .include(LatLng(boundary.swLat, boundary.swLng))
            .build()
        easeCamera(CameraUpdateFactory.newLatLngBounds(bounds, BOUNDARY_PADDING_PX))
        hasFramedArea.value = true
    }
}

private fun circleLayer(layerId: String, sourceId: String, color: String, radius: Float): CircleLayer =
    CircleLayer(layerId, sourceId).withProperties(
        PropertyFactory.circleRadius(radius),
        PropertyFactory.circleColor(color),
        PropertyFactory.circleStrokeWidth(CIRCLE_STROKE_WIDTH),
        PropertyFactory.circleStrokeColor(CIRCLE_STROKE_COLOR),
    )

private fun initialsLayer(layerId: String, sourceId: String): SymbolLayer =
    SymbolLayer(layerId, sourceId).withProperties(
        PropertyFactory.textField(Expression.get(PROPERTY_INITIALS)),
        PropertyFactory.textFont(LABEL_FONT),
        PropertyFactory.textOffset(arrayOf(0f, 0f)),
        PropertyFactory.textAnchor(Property.TEXT_ANCHOR_CENTER),
        PropertyFactory.textAllowOverlap(true),
        PropertyFactory.textSize(INITIALS_TEXT_SIZE),
        PropertyFactory.textColor(INITIALS_TEXT_COLOR),
    )

private fun labelLayer(layerId: String, sourceId: String): SymbolLayer =
    SymbolLayer(layerId, sourceId).withProperties(
        PropertyFactory.textField(Expression.get(PROPERTY_DISPLAY_NAME)),
        PropertyFactory.textFont(LABEL_FONT),
        PropertyFactory.textOffset(arrayOf(0f, LABEL_OFFSET_Y)),
        PropertyFactory.textAnchor(Property.TEXT_ANCHOR_TOP),
        PropertyFactory.textAllowOverlap(true),
        PropertyFactory.textSize(LABEL_TEXT_SIZE),
        PropertyFactory.textColor(LABEL_TEXT_COLOR),
        PropertyFactory.textHaloColor(LABEL_HALO_COLOR),
        PropertyFactory.textHaloWidth(LABEL_HALO_WIDTH),
    )

private fun toFeatureCollectionJson(markers: List<PlayerMarker>): String {
    val collection = FeatureCollectionDto(
        features = markers.map {
            FeatureDto(
                geometry = GeometryDto(coordinates = listOf(it.longitude, it.latitude)),
                properties = PropertiesDto(displayName = it.displayName, initials = it.initials),
            )
        },
    )
    return geoJson.encodeToString(collection)
}

@Serializable
private data class FeatureCollectionDto(
    val type: String = "FeatureCollection",
    val features: List<FeatureDto>,
)

@Serializable
private data class FeatureDto(
    val type: String = "Feature",
    val geometry: GeometryDto,
    val properties: PropertiesDto,
)

@Serializable
private data class GeometryDto(
    val type: String = "Point",
    val coordinates: List<Double>,
)

@Serializable
private data class PropertiesDto(
    val displayName: String,
    val initials: String = "",
)

private const val BOUNDARY_SOURCE_ID = "boundary-source"
private const val BOUNDARY_FILL_LAYER_ID = "boundary-fill"
private const val BOUNDARY_LINE_LAYER_ID = "boundary-line"
private const val SELF_SOURCE_ID = "self-location-source"
private const val OTHERS_SOURCE_ID = "other-players-source"
private const val SELF_LABEL_SOURCE_ID = "self-label-source"
private const val OTHERS_LABEL_SOURCE_ID = "other-players-label-source"
private const val ZONE_SOURCE_ID = "hiding-zone-source"
private const val SELF_CIRCLE_LAYER_ID = "self-location-circle"
private const val SELF_LABEL_LAYER_ID = "self-location-label"
private const val SELF_INITIALS_LAYER_ID = "self-initials-label"
private const val OTHERS_CIRCLE_LAYER_ID = "other-players-circle"
private const val OTHERS_LABEL_LAYER_ID = "other-players-label"
private const val OTHERS_INITIALS_LAYER_ID = "other-players-initials"
private const val ZONE_FILL_LAYER_ID = "hiding-zone-fill"
private const val ZONE_LINE_LAYER_ID = "hiding-zone-line"
private const val SEEKER_MARKER_SOURCE_ID = "seeker-candidate-source"
private const val SEEKER_MARKER_FILL_LAYER_ID = "seeker-candidate-fill"
private const val SEEKER_MARKER_LINE_LAYER_ID = "seeker-candidate-line"
private val LABEL_FONT = arrayOf("Noto Sans Regular")
private const val PROPERTY_DISPLAY_NAME = "displayName"
private const val PROPERTY_INITIALS = "initials"
private const val BOUNDARY_FILL_COLOR = "#3B82F6"
private const val BOUNDARY_FILL_OPACITY = 0.08f
private const val BOUNDARY_LINE_COLOR = "#3B82F6"
private const val BOUNDARY_LINE_WIDTH = 2f
private const val SELF_MARKER_COLOR = "#2B3947"
private const val OTHER_MARKER_COLOR = "#E0402E"
private const val ZONE_FILL_COLOR = "#F4C04C"
private const val ZONE_FILL_OPACITY = 0.2f
private const val ZONE_LINE_COLOR = "#F4C04C"
private const val ZONE_LINE_WIDTH = 2f
private const val SEEKER_MARKER_FILL_OPACITY = 0.08f
private const val SEEKER_MARKER_LINE_WIDTH = 2.5f
private val SEEKER_MARKER_LINE_DASHES = arrayOf(2f, 2f)
private const val CIRCLE_STROKE_COLOR = "#FFFFFF"
private const val CIRCLE_RADIUS = 8f
private const val SELF_MARKER_RADIUS = 14f
private const val CIRCLE_STROKE_WIDTH = 2f
private const val LABEL_OFFSET_Y = 1.5f
private const val LABEL_TEXT_SIZE = 12f
private const val LABEL_TEXT_COLOR = "#FFFFFF"
private const val LABEL_HALO_COLOR = "#000000"
private const val LABEL_HALO_WIDTH = 1.5f
private const val INITIALS_TEXT_SIZE = 10f
private const val INITIALS_TEXT_COLOR = "#FFFFFF"
private const val BOUNDARY_PADDING_PX = 80
internal fun Style.ensureBoundaryLayer() {
    if (getSource(BOUNDARY_SOURCE_ID) != null) return

    addSource(GeoJsonSource(BOUNDARY_SOURCE_ID))
    addLayer(
        FillLayer(BOUNDARY_FILL_LAYER_ID, BOUNDARY_SOURCE_ID).withProperties(
            PropertyFactory.fillColor(BOUNDARY_FILL_COLOR),
            PropertyFactory.fillOpacity(BOUNDARY_FILL_OPACITY),
        ),
    )
    addLayer(
        LineLayer(BOUNDARY_LINE_LAYER_ID, BOUNDARY_SOURCE_ID).withProperties(
            PropertyFactory.lineColor(BOUNDARY_LINE_COLOR),
            PropertyFactory.lineWidth(BOUNDARY_LINE_WIDTH),
        ),
    )
}

internal fun Style.updateBoundarySource(geoJson: String?) {
    (getSource(BOUNDARY_SOURCE_ID) as? GeoJsonSource)?.setGeoJson(
        geoJson ?: EMPTY_GEOJSON,
    )
}

private const val EMPTY_ZONE_GEOJSON = "{\"type\":\"FeatureCollection\",\"features\":[]}"
private const val EMPTY_GEOJSON = "{\"type\":\"FeatureCollection\",\"features\":[]}"
private const val EARTH_RADIUS_METERS = 6_371_000.0
private const val DEG_TO_RAD = PI / 180.0
private const val RAD_TO_DEG = 180.0 / PI
private const val CIRCLE_TWO_PI = 2.0 * PI
private const val CIRCLE_POLYGON_POINTS = 64
