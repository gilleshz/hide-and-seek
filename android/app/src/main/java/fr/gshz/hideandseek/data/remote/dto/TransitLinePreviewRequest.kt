package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class TransitLinePreviewRequest(
    val osmIds: List<String>,
)
