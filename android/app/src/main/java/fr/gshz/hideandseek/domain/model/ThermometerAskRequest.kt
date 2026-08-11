package fr.gshz.hideandseek.domain.model

data class ThermometerAskRequest(
    val roundUuid: String,
    val askerPlayerUuid: String,
    val startLat: Double,
    val startLng: Double,
    val distanceMeters: Double,
)
