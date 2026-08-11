package fr.gshz.hideandseek.domain.model

data class ManualConstraint(
    val uuid: String,
    val mode: ConstraintMode,
    val geoJson: String,
    val label: String,
    val createdByName: String?,
)
