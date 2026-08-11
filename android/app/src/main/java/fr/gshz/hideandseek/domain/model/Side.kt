package fr.gshz.hideandseek.domain.model

enum class Side(val wireValue: String) {
    Hider("hider"),
    Seeker("seeker"),
    ;

    companion object {
        fun fromWireValue(value: String): Side = entries.first { it.wireValue == value }

        fun fromWireValueOrNull(value: String?): Side? = entries.firstOrNull { it.wireValue == value }
    }
}
