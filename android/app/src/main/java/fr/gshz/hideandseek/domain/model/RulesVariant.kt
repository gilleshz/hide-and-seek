package fr.gshz.hideandseek.domain.model

enum class RulesVariant(val wireValue: String) {
    Official("official"),
    Compact("compact"),
    ;

    companion object {
        /** The backend omits default fields, and an unknown value is a newer server: both mean official. */
        fun fromWireValue(value: String?): RulesVariant =
            entries.firstOrNull { it.wireValue == value } ?: Official
    }
}
