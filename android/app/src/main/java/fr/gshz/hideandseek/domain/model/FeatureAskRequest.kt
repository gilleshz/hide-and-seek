package fr.gshz.hideandseek.domain.model

data class FeatureAskRequest(
    val roundUuid: String,
    val askerPlayerUuid: String,
    val category: QuestionCategory,
    val seekerLat: Double,
    val seekerLng: Double,
    val featureType: FeatureType? = null,
    val withinMeters: Double? = null,
    val transitLineOsmId: String? = null,
    val transitLineOsmType: String? = null,
    val stationNameLength: Boolean = false,
    val seaLevel: Boolean = false,
    val seekerAltitude: Double? = null,
)
