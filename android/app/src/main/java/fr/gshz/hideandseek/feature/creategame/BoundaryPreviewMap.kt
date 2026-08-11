package fr.gshz.hideandseek.feature.creategame

import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.MutableState
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import fr.gshz.hideandseek.feature.map.MapConstants
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject
import org.maplibre.android.MapLibre
import org.maplibre.android.camera.CameraUpdateFactory
import org.maplibre.android.geometry.LatLng
import org.maplibre.android.geometry.LatLngBounds
import org.maplibre.android.maps.MapLibreMap
import org.maplibre.android.maps.MapView
import org.maplibre.android.maps.Style
import org.maplibre.android.style.expressions.Expression
import org.maplibre.android.style.layers.FillLayer
import org.maplibre.android.style.layers.LineLayer
import org.maplibre.android.style.layers.Property
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.sources.GeoJsonSource

private const val PREVIEW_BOUNDARY_SOURCE_ID = "preview-boundary-source"
private const val PREVIEW_BOUNDARY_FILL_LAYER_ID = "preview-boundary-fill"
private const val PREVIEW_BOUNDARY_LINE_LAYER_ID = "preview-boundary-line"
private const val PREVIEW_BOUNDARY_FILL_COLOR = "#4CAF50"
private const val PREVIEW_BOUNDARY_FILL_OPACITY = 0.12f
private const val PREVIEW_BOUNDARY_LINE_COLOR = "#4CAF50"
private const val PREVIEW_BOUNDARY_LINE_WIDTH = 2.5f
private const val PREVIEW_TRANSIT_SOURCE_ID = "preview-transit-source"
private const val PREVIEW_TRANSIT_LINE_LAYER_ID = "preview-transit-line"
private const val PREVIEW_TRANSIT_COLOUR_PROPERTY = "colour"
private const val PREVIEW_TRANSIT_FALLBACK_COLOR = "#4A90D9"
private const val PREVIEW_TRANSIT_LINE_WIDTH = 1.8f
private const val EMPTY_FEATURE_COLLECTION = """{"type":"FeatureCollection","features":[]}"""

@Composable
internal fun BoundaryPreviewMap(
    geoJson: String?,
    modifier: Modifier = Modifier,
    transitGeoJson: String? = null,
) {
    if (geoJson == null) return

    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current.lifecycle

    val mapView = remember {
        MapLibre.getInstance(context)
        MapView(context)
    }
    val fittedGeoJson = remember { mutableStateOf<String?>(null) }

    DisposableEffect(lifecycle, mapView) {
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> mapView.onStart()
                Lifecycle.Event.ON_RESUME -> mapView.onResume()
                Lifecycle.Event.ON_PAUSE -> mapView.onPause()
                Lifecycle.Event.ON_STOP -> mapView.onStop()
                else -> {}
            }
        }
        lifecycle.addObserver(observer)
        onDispose {
            lifecycle.removeObserver(observer)
            mapView.onDestroy()
        }
    }

    AndroidView(
        factory = { mapView },
        modifier = modifier,
        update = { view ->
            view.getMapAsync { map -> drawPreview(map, geoJson, transitGeoJson, fittedGeoJson) }
        },
    )
}

/** Re-fits the camera only when the boundary itself changed, so redrawing lines keeps the view. */
private fun drawPreview(
    map: MapLibreMap,
    geoJson: String,
    transitGeoJson: String?,
    fittedGeoJson: MutableState<String?>,
) {
    val style = map.style
    if (style == null) {
        map.setStyle(Style.Builder().fromUri(MapConstants.STYLE_URL)) { loaded ->
            applyPreviewBoundary(loaded, geoJson)
            applyPreviewTransit(loaded, transitGeoJson)
            fitToBoundary(map, geoJson)
            fittedGeoJson.value = geoJson
        }
        return
    }

    applyPreviewBoundary(style, geoJson)
    applyPreviewTransit(style, transitGeoJson)
    if (fittedGeoJson.value != geoJson) {
        fitToBoundary(map, geoJson)
        fittedGeoJson.value = geoJson
    }
}

private const val BOUNDARY_FIT_PADDING_PX = 48

private fun fitToBoundary(map: MapLibreMap, geoJson: String) {
    val bounds = boundsOf(geoJson) ?: return
    try {
        map.moveCamera(CameraUpdateFactory.newLatLngBounds(bounds, BOUNDARY_FIT_PADDING_PX))
    } catch (_: IllegalStateException) {
        // Map not measured yet; the next recomposition re-fits once it has dimensions.
    }
}

private fun boundsOf(geoJson: String): LatLngBounds? {
    val points = parseBoundaryPoints(geoJson)
    if (points.size < 2) return null
    return buildBounds(points)
}

private fun parseBoundaryPoints(geoJson: String): List<LatLng> {
    val points = mutableListOf<LatLng>()
    try {
        collectFeaturePoints(JSONObject(geoJson), points)
    } catch (_: JSONException) {
        return emptyList()
    }
    return points
}

private fun buildBounds(points: List<LatLng>): LatLngBounds? =
    try {
        LatLngBounds.Builder().includes(points).build()
    } catch (_: IllegalArgumentException) {
        null
    }

private fun collectFeaturePoints(root: JSONObject, out: MutableList<LatLng>) {
    val features = root.optJSONArray("features") ?: return
    for (i in 0 until features.length()) {
        val geometry = features.optJSONObject(i)?.optJSONObject("geometry") ?: continue
        collectCoordinates(geometry.optJSONArray("coordinates"), out)
    }
}

private fun collectCoordinates(node: JSONArray?, out: MutableList<LatLng>) {
    if (node == null) return
    if (isLeafPoint(node)) {
        out.add(LatLng(node.optDouble(1), node.optDouble(0)))
        return
    }
    for (i in 0 until node.length()) {
        collectCoordinates(node.optJSONArray(i), out)
    }
}

private fun isLeafPoint(node: JSONArray): Boolean {
    if (node.length() != 2 || node.optJSONArray(0) != null) return false
    return !node.optDouble(0, Double.NaN).isNaN() && !node.optDouble(1, Double.NaN).isNaN()
}

private fun applyPreviewBoundary(style: Style, geoJson: String) {
    if (style.getSource(PREVIEW_BOUNDARY_SOURCE_ID) == null) {
        style.addSource(GeoJsonSource(PREVIEW_BOUNDARY_SOURCE_ID))
        style.addLayer(
            FillLayer(PREVIEW_BOUNDARY_FILL_LAYER_ID, PREVIEW_BOUNDARY_SOURCE_ID).withProperties(
                PropertyFactory.fillColor(PREVIEW_BOUNDARY_FILL_COLOR),
                PropertyFactory.fillOpacity(PREVIEW_BOUNDARY_FILL_OPACITY),
            ),
        )
        style.addLayer(
            LineLayer(PREVIEW_BOUNDARY_LINE_LAYER_ID, PREVIEW_BOUNDARY_SOURCE_ID).withProperties(
                PropertyFactory.lineColor(PREVIEW_BOUNDARY_LINE_COLOR),
                PropertyFactory.lineWidth(PREVIEW_BOUNDARY_LINE_WIDTH),
            ),
        )
    }
    (style.getSource(PREVIEW_BOUNDARY_SOURCE_ID) as? GeoJsonSource)?.setGeoJson(geoJson)
}

/**
 * Raw OSM ways, not the LOOM overlay the game will get: shared corridors draw on top of each other
 * here, which is enough to check the selection covers the right lines.
 */
private fun applyPreviewTransit(style: Style, geoJson: String?) {
    if (style.getSource(PREVIEW_TRANSIT_SOURCE_ID) == null) {
        style.addSource(GeoJsonSource(PREVIEW_TRANSIT_SOURCE_ID))
        style.addLayer(
            LineLayer(PREVIEW_TRANSIT_LINE_LAYER_ID, PREVIEW_TRANSIT_SOURCE_ID).withProperties(
                PropertyFactory.lineColor(
                    Expression.toColor(
                        Expression.coalesce(
                            Expression.get(PREVIEW_TRANSIT_COLOUR_PROPERTY),
                            Expression.literal(PREVIEW_TRANSIT_FALLBACK_COLOR),
                        ),
                    ),
                ),
                PropertyFactory.lineWidth(PREVIEW_TRANSIT_LINE_WIDTH),
                PropertyFactory.lineCap(Property.LINE_CAP_ROUND),
                PropertyFactory.lineJoin(Property.LINE_JOIN_ROUND),
            ),
        )
    }
    (style.getSource(PREVIEW_TRANSIT_SOURCE_ID) as? GeoJsonSource)
        ?.setGeoJson(geoJson ?: EMPTY_FEATURE_COLLECTION)
}
