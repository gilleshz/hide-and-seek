package fr.gshz.hideandseek.domain.model

enum class ThermometerResult(val wireValue: String) {
    Hotter("hotter"),
    Colder("colder"),
    ;

    companion object {
        fun fromWireValue(value: String): ThermometerResult? = entries.firstOrNull { it.wireValue == value }
    }
}
