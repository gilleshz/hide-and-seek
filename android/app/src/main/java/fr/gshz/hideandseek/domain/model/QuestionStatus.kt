package fr.gshz.hideandseek.domain.model

enum class QuestionStatus(val wireValue: String) {
    Open("open"),
    Vetoed("vetoed"),
    Randomized("randomized");

    companion object {
        fun fromWireValue(value: String): QuestionStatus =
            entries.first { it.wireValue == value }
    }
}
