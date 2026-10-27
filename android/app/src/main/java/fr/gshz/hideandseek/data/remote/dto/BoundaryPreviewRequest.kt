package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class BoundaryPreviewRequest(
    val areas: List<AreaRef> = emptyList(),
    val osmLineIds: List<String>? = null,
    val gtfsLines: List<BoundaryGtfsLine>? = null,
    val size: String? = null,
    val edition: String? = null,
)

@Serializable
data class BoundaryGtfsLine(
    val gtfsSourceUuid: String,
    val routeId: String,
)
