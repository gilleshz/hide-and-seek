package fr.gshz.hideandseek.domain.model

data class LocationUpdate(
    val playerUuid: String,
    val latitude: Double,
    val longitude: Double,
    val recordedAt: String,
)
