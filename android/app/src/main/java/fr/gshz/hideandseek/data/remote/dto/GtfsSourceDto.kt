package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class GtfsSourceDto(
    val uuid: String,
    val name: String,
    val routes: List<GtfsRouteEntryDto>,
)

@Serializable
data class GtfsRouteEntryDto(
    val routeId: String = "",
    val shortName: String = "",
    val longName: String = "",
    val routeType: Int = 0,
    val color: String = "",
    val textColor: String = "",
)
