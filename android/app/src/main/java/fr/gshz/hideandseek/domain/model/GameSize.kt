package fr.gshz.hideandseek.domain.model

enum class GameSize(val wireValue: String) {
    Small("S"),
    Medium("M"),
    Large("L"),
    ;

    companion object {
        fun fromWireValue(value: String): GameSize = entries.first { it.wireValue == value }
    }
}
