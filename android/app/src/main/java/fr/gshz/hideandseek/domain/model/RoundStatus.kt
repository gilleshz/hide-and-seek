package fr.gshz.hideandseek.domain.model

enum class RoundStatus(val wireValue: String) {
    Lobby("lobby"),
    Hiding("hiding"),
    Seeking("seeking"),
    Ended("ended"),
    ;

    companion object {
        fun fromWireValue(value: String): RoundStatus = entries.first { it.wireValue == value }

        fun fromWireValueOrNull(value: String): RoundStatus? = entries.firstOrNull { it.wireValue == value }
    }
}
