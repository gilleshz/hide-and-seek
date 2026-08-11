package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class ManualConstraintDto(
    val uuid: String,
    val mode: String,
    val geoJson: String,
    val source: String? = null,
    val label: String? = null,
    val createdByName: String? = null,
)

@Serializable
data class AddManualConstraintRequest(
    val playerUuid: String,
    val geoJson: String,
    val mode: String,
    val label: String? = null,
)
