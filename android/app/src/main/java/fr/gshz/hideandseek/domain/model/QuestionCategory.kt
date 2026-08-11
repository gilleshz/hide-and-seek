package fr.gshz.hideandseek.domain.model

enum class QuestionCategory(val wireValue: String) {
    Radar("radar"),
    Thermometer("thermometer"),
    Matching("matching"),
    Measuring("measuring"),
    Tentacles("tentacles"),
    Photos("photos"),
    ;

    companion object {
        fun fromWireValue(value: String): QuestionCategory? = entries.firstOrNull { it.wireValue == value }
    }
}
