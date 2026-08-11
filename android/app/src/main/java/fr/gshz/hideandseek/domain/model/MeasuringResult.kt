package fr.gshz.hideandseek.domain.model

enum class MeasuringResult(val wireValue: String) {
    Closer("closer"),
    Further("further"),
    ;

    companion object {
        fun fromWireValue(value: String): MeasuringResult? = entries.firstOrNull { it.wireValue == value }
    }
}
