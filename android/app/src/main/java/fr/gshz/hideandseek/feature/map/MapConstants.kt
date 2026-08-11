package fr.gshz.hideandseek.feature.map

import org.json.JSONArray
import org.json.JSONObject

internal object MapConstants {
    const val STYLE_URL = "asset://map_style.json"
    const val PMTILES_STYLE_URL = "http://10.0.2.2:8080/tiles/region.pmtiles"
    const val IGN_PLAN_STYLE_URL = "asset://map_style_ign_plan.json"
    const val IGN_ORTHO_STYLE_URL = "asset://map_style_ign_ortho.json"
    const val DEFAULT_ZOOM = 15.0

    // The shaded (excluded) region is red, distinct from the blue proven area and yellow zone.
    const val CONSTRAINT_EXCLUDE_COLOR = "#DC2626"

    // The hider's traced street. Deliberately the same red today; its own constant so the trace can be
    // recoloured without disturbing the possible-area legend.
    const val TRACE_COLOR = "#DC2626"
    private const val ATLAS_TILE_SIZE = 256
    private const val ATLAS_STYLE_VERSION = 8

    fun stadiaStyleUrl(style: String, key: String): String =
        "https://tiles.stadiamaps.com/styles/$style.json?api_key=$key"

    fun maptilerHybridStyleUrl(key: String): String =
        "https://api.maptiler.com/maps/hybrid/style.json?key=$key"

    fun atlasStyleJson(apiKey: String): String {
        val tiles = JSONArray().apply {
            put("https://tile.thunderforest.com/atlas/{z}/{x}/{y}.png?apikey=$apiKey")
        }
        val source = JSONObject().apply {
            put("type", "raster")
            put("tiles", tiles)
            put("tileSize", ATLAS_TILE_SIZE)
            put(
                "attribution",
                "Maps © <a href=\"https://www.thunderforest.com/\">Thunderforest</a>, " +
                    "Data © <a href=\"https://www.openstreetmap.org/copyright\">" +
                    "OpenStreetMap contributors</a>",
            )
        }
        val sources = JSONObject().apply {
            put("thunderforest-atlas", source)
        }
        val layer = JSONObject().apply {
            put("id", "thunderforest-atlas")
            put("type", "raster")
            put("source", "thunderforest-atlas")
        }
        val layers = JSONArray().apply {
            put(layer)
        }
        return JSONObject().apply {
            put("version", ATLAS_STYLE_VERSION)
            put("name", "Thunderforest Atlas")
            put("sources", sources)
            put("layers", layers)
        }.toString()
    }
}
