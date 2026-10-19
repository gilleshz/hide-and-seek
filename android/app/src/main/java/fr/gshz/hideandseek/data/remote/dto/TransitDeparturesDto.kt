package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class StationDeparturesDto(
    val stationId: String = "",
    val uuid: String = "",
    val stationName: String = "",
    val timezone: String = "",
    val serverNow: Long = 0,
    val groups: List<DepartureGroupDto> = emptyList(),
)

@Serializable
data class DepartureGroupDto(
    val routeId: String = "",
    val lineRef: String = "",
    val lineName: String = "",
    val colour: String = "",
    val headsign: String = "",
    val directionId: Int = 0,
    val realtime: Boolean = false,
    val departures: List<DepartureDto> = emptyList(),
)

@Serializable
data class DepartureDto(
    val at: Long = 0,
    val inSeconds: Int = 0,
    val realtime: Boolean = false,
    val delaySeconds: Int = 0,
    val headsign: String = "",
)
