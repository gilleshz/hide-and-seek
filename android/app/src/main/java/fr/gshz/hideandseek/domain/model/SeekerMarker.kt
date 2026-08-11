package fr.gshz.hideandseek.domain.model

data class SeekerMarker(
    val uuid: String,
    val playerUuid: String?,
    val lat: Double,
    val lng: Double,
)
