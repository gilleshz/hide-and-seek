package fr.gshz.hideandseek.feature.map

sealed class StyleSource {
    data class Uri(val url: String) : StyleSource()
    data class Json(val json: String) : StyleSource()
}
