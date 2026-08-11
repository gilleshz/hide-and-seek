package fr.gshz.hideandseek.feature.map

enum class MapStyle(val styleKey: String) {
    Standard("default"),
    OsmBright("osm_bright"),
    Dark("alidade_smooth_dark"),
    Atlas("thunderforest_atlas"),
    Satellite("maptiler_hybrid"),
    IgnPlan("ign_plan"),
    IgnOrtho("ign_ortho");

    fun resolveSource(
        stadiaApiKey: String?,
        thunderforestApiKey: String?,
        maptilerApiKey: String?,
    ): StyleSource? = when (this) {
        Standard -> StyleSource.Uri(MapConstants.STYLE_URL)
        OsmBright -> stadiaApiKey?.let { StyleSource.Uri(MapConstants.stadiaStyleUrl("osm_bright", it)) }
        Dark -> stadiaApiKey?.let { StyleSource.Uri(MapConstants.stadiaStyleUrl("alidade_smooth_dark", it)) }
        Atlas -> thunderforestApiKey?.let { StyleSource.Json(MapConstants.atlasStyleJson(it)) }
        Satellite -> maptilerApiKey?.let { StyleSource.Uri(MapConstants.maptilerHybridStyleUrl(it)) }
        IgnPlan -> StyleSource.Uri(MapConstants.IGN_PLAN_STYLE_URL)
        IgnOrtho -> StyleSource.Uri(MapConstants.IGN_ORTHO_STYLE_URL)
    }
}
