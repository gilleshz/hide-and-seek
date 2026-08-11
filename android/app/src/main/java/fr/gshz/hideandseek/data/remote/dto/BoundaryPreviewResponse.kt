package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class BoundaryPreviewResponse(
    val id: String = "",
    val geoJson: String = "",
)
