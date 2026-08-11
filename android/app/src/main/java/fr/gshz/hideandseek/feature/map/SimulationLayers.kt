package fr.gshz.hideandseek.feature.map

import org.json.JSONObject
import org.maplibre.android.maps.Style
import org.maplibre.android.style.layers.CircleLayer
import org.maplibre.android.style.layers.FillLayer
import org.maplibre.android.style.layers.LineLayer
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.layers.SymbolLayer
import org.maplibre.android.style.sources.GeoJsonSource

private const val SIMULATION_SOURCE_ID = "simulation-source"
private const val SIMULATION_FILL_LAYER_ID = "simulation-fill"
private const val SIMULATION_LINE_LAYER_ID = "simulation-line"
private const val SIMULATION_FILL_COLOR = "#3B82F6"
private const val SIMULATION_FILL_OPACITY = 0.15f
private const val SIMULATION_LINE_COLOR = "#3B82F6"
private const val SIMULATION_LINE_WIDTH = 2.5f
private const val SIMULATION_LINE_OPACITY = 0.8f
private const val EMPTY_COLLECTION = """{"type":"FeatureCollection","features":[]}"""

internal fun Style.ensureSimulationLayer() {
    if (getSource(SIMULATION_SOURCE_ID) != null) return
    addSource(GeoJsonSource(SIMULATION_SOURCE_ID))
    addLayer(
        FillLayer(SIMULATION_FILL_LAYER_ID, SIMULATION_SOURCE_ID).withProperties(
            PropertyFactory.fillColor(SIMULATION_FILL_COLOR),
            PropertyFactory.fillOpacity(SIMULATION_FILL_OPACITY),
        ),
    )
    addLayer(
        LineLayer(SIMULATION_LINE_LAYER_ID, SIMULATION_SOURCE_ID).withProperties(
            PropertyFactory.lineColor(SIMULATION_LINE_COLOR),
            PropertyFactory.lineWidth(SIMULATION_LINE_WIDTH),
            PropertyFactory.lineOpacity(SIMULATION_LINE_OPACITY),
        ),
    )
}

internal fun Style.updateSimulationSource(geoJson: String?) {
    val source = getSource(SIMULATION_SOURCE_ID) as? GeoJsonSource ?: return
    source.setGeoJson(geoJson?.let(::asFeatureCollectionJson) ?: EMPTY_COLLECTION)
}

private const val SIM_PIN_SOURCE_ID = "simulation-pin-source"
private const val SIM_PIN_LAYER_ID = "simulation-pin"
private const val SIM_PIN_COLOR = "#1D4ED8"
private const val SIM_PIN_RADIUS = 9f
private const val SIM_PIN_STROKE_COLOR = "#FFFFFF"
private const val SIM_PIN_STROKE_WIDTH = 3f

internal fun Style.ensureSimulationPinLayer() {
    if (getSource(SIM_PIN_SOURCE_ID) != null) return
    addSource(GeoJsonSource(SIM_PIN_SOURCE_ID))
    addLayer(
        CircleLayer(SIM_PIN_LAYER_ID, SIM_PIN_SOURCE_ID).withProperties(
            PropertyFactory.circleColor(SIM_PIN_COLOR),
            PropertyFactory.circleRadius(SIM_PIN_RADIUS),
            PropertyFactory.circleStrokeColor(SIM_PIN_STROKE_COLOR),
            PropertyFactory.circleStrokeWidth(SIM_PIN_STROKE_WIDTH),
        ),
    )
}

internal fun Style.updateSimulationPinSource(geoJson: String?) {
    val source = getSource(SIM_PIN_SOURCE_ID) as? GeoJsonSource ?: return
    source.setGeoJson(geoJson ?: EMPTY_COLLECTION)
}

// The server returns bare geometry (ST_AsGeoJSON); the source only renders Feature(Collection)s.
private fun asFeatureCollectionJson(geoJson: String): String {
    val type = runCatching { JSONObject(geoJson).optString("type") }.getOrNull()
    if (type == "FeatureCollection" || type == "Feature") return geoJson
    return """{"type":"FeatureCollection","features":[{"type":"Feature","geometry":$geoJson,"properties":{}}]}"""
}

private const val CANDIDATE_SOURCE_ID = "candidate-poi-source"
private const val CANDIDATE_CIRCLE_LAYER_ID = "candidate-poi-circle"
private const val CANDIDATE_LABEL_LAYER_ID = "candidate-poi-label"
private const val CANDIDATE_CIRCLE_COLOR = "#10B981"
private const val CANDIDATE_CIRCLE_RADIUS = 7f
private const val CANDIDATE_CIRCLE_OPACITY = 0.8f
private const val CANDIDATE_STROKE_COLOR = "#FFFFFF"
private const val CANDIDATE_STROKE_WIDTH = 2f
private const val CANDIDATE_LABEL_SIZE = 10f
private const val CANDIDATE_LABEL_OFFSET = 1.8f
private const val HALO_WIDTH = 1.5f

internal fun Style.ensureCandidatePoiLayer() {
    if (getSource(CANDIDATE_SOURCE_ID) != null) return
    addSource(GeoJsonSource(CANDIDATE_SOURCE_ID))
    addLayer(
        CircleLayer(CANDIDATE_CIRCLE_LAYER_ID, CANDIDATE_SOURCE_ID).withProperties(
            PropertyFactory.circleColor(CANDIDATE_CIRCLE_COLOR),
            PropertyFactory.circleRadius(CANDIDATE_CIRCLE_RADIUS),
            PropertyFactory.circleOpacity(CANDIDATE_CIRCLE_OPACITY),
            PropertyFactory.circleStrokeColor(CANDIDATE_STROKE_COLOR),
            PropertyFactory.circleStrokeWidth(CANDIDATE_STROKE_WIDTH),
        ),
    )
    addLayer(
        SymbolLayer(CANDIDATE_LABEL_LAYER_ID, CANDIDATE_SOURCE_ID).withProperties(
            PropertyFactory.textField("{name}"),
            PropertyFactory.textSize(CANDIDATE_LABEL_SIZE),
            PropertyFactory.textOffset(arrayOf(0f, CANDIDATE_LABEL_OFFSET)),
            PropertyFactory.textAnchor("top"),
            PropertyFactory.textColor("#1F2937"),
            PropertyFactory.textHaloColor("#FFFFFF"),
            PropertyFactory.textHaloWidth(HALO_WIDTH),
        ),
    )
}

internal fun Style.updateCandidatePoiSource(geoJson: String?) {
    val source = getSource(CANDIDATE_SOURCE_ID) as? GeoJsonSource ?: return
    source.setGeoJson(geoJson ?: EMPTY_COLLECTION)
}
