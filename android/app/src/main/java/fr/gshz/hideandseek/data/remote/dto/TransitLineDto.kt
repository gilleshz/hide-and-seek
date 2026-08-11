package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class TransitLineDto(
    val osmId: String = "",
    val osmType: String = "",
    val ref: String = "",
    val name: String = "",
    val nameEn: String = "",
    val colour: String = "",
    val routeType: String = "",
    val network: String = "",
    val operator: String = "",
)
