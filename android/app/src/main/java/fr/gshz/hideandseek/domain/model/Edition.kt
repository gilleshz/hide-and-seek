package fr.gshz.hideandseek.domain.model

enum class Edition(val wireValue: String) {
    Metric("metric"),
    Imperial("imperial"),
    ;

    companion object {
        fun fromWireValue(value: String): Edition = entries.first { it.wireValue == value }
    }
}
