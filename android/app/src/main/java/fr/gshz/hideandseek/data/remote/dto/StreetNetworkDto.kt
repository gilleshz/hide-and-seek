package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class StreetNetworkDto(
    val roundUuid: String = "",
    val status: String = "",
    val fetchedAt: String? = null,
    val wayCount: Int = 0,
    val ways: List<StreetWayDto> = emptyList(),
)

@Serializable
data class StreetWayDto(
    @SerialName("class") val streetClass: String = "",
    // GeoJSON order: each pair is [longitude, latitude].
    val coordinates: List<List<Double>> = emptyList(),
    val junctionIndices: List<Int> = emptyList(),
)
