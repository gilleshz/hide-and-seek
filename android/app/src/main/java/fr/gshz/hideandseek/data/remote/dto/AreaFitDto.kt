package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class AreaFitRequest(
    val osmLineIds: List<String>,
    val gtfsLines: List<AreaFitGtfsLine>,
)

@Serializable
data class AreaFitGtfsLine(
    val gtfsSourceUuid: String,
    val routeId: String,
)

@Serializable
data class AreaFitResponse(
    val areas: List<AreaSearchResult> = emptyList(),
)
