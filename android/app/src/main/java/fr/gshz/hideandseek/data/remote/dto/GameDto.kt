package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class AreaRef(
    val osmType: String,
    val osmId: Int,
)

@Serializable
data class TransitLineRef(
    val osmType: String,
    val osmId: Int,
    val ref: String = "",
    val name: String = "",
    val nameEn: String = "",
    val colour: String = "",
    val routeType: String = "",
    val network: String = "",
    val operator: String = "",
)

@Serializable
data class GtfsLineRef(
    val gtfsSourceUuid: String,
    val routeId: String,
    val ref: String,
    val name: String,
    val colour: String,
    val routeType: String,
    val network: String,
    val operator: String,
)

@Serializable
data class GameCreateRequest(
    val name: String,
    val size: String,
    val edition: String,
    val boundarySwLat: Double? = null,
    val boundarySwLng: Double? = null,
    val boundaryNeLat: Double? = null,
    val boundaryNeLng: Double? = null,
    val areas: List<AreaRef>? = null,
    val selectedTransitLines: List<TransitLineRef>? = null,
    val selectedGtfsLines: List<GtfsLineRef>? = null,
)

@Serializable
data class AreaSearchResult(
    val osmId: String,
    val osmType: String,
    val displayName: String,
    val adminLevel: Int? = null,
)

@Serializable
data class GameDto(
    val uuid: String,
    val name: String,
    val size: String,
    val edition: String,
    val createdAt: String,
    val roundUuid: String,
    val boundarySwLat: Double? = null,
    val boundarySwLng: Double? = null,
    val boundaryNeLat: Double? = null,
    val boundaryNeLng: Double? = null,
    val transitTilesPath: String? = null,
    val joinCode: String? = null,
    val boundaryGeoJson: String? = null,
    val defaultHidingPeriodMinutes: Int? = null,
    val selectedTransitLines: List<TransitLineRef> = emptyList(),
)

@Serializable
data class GameConfigRequest(
    val name: String? = null,
    val size: String? = null,
    val edition: String? = null,
)
