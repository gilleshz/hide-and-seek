package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class BoundaryPreviewRequest(
    val areas: List<AreaRef>,
)
