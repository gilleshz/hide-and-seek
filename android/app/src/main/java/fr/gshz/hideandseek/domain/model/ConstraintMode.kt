package fr.gshz.hideandseek.domain.model

enum class ConstraintMode(val wireValue: String) {
    Include("include"),
    Exclude("exclude"),
    ;

    companion object {
        fun fromWireValueOrNull(value: String?): ConstraintMode? =
            entries.firstOrNull { it.wireValue == value }
    }
}
