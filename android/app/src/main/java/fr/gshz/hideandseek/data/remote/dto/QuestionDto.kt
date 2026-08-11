package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class AskQuestionRequest(
    val askerPlayerUuid: String,
    val category: String,
    val radiusMeters: Double? = null,
    val seekerLat: Double? = null,
    val seekerLng: Double? = null,
    val startLat: Double? = null,
    val startLng: Double? = null,
    val distanceMeters: Double? = null,
    val featureType: String? = null,
    val withinMeters: Double? = null,
    val photoTarget: String? = null,
    val isCustomRadius: Boolean = false,
    val transitLineOsmId: String? = null,
    val transitLineOsmType: String? = null,
    val stationNameLength: Boolean? = null,
    val seaLevel: Boolean? = null,
    val seekerAltitude: Double? = null,
)

@Serializable
data class RevealQuestionRequest(val revealingPlayerUuid: String)

@Serializable
data class CompleteThermometerRequest(
    val askerPlayerUuid: String,
    val endLat: Double,
    val endLng: Double,
)

@Serializable
data class AskedQuestionDto(
    val uuid: String,
    val roundUuid: String,
    val category: String,
    val askedAt: String,
    val revealDeadlineAt: String? = null,
    val revealedAt: String? = null,
    val featureType: String? = null,
    val withinMeters: Double? = null,
    val radiusMeters: Double? = null,
    val distanceMeters: Double? = null,
    val seekerLat: Double? = null,
    val seekerLng: Double? = null,
    val startLat: Double? = null,
    val startLng: Double? = null,
    val endLat: Double? = null,
    val endLng: Double? = null,
    val photoTarget: String? = null,
    val radarAnswer: Boolean? = null,
    val thermometerAnswer: String? = null,
    val matchingAnswer: Boolean? = null,
    val measuringAnswer: String? = null,
    val tentaclesAnswer: String? = null,
    val transitLineLabel: String? = null,
    val status: String? = null,
    val replacedByUuid: String? = null,
    val replacedQuestionUuid: String? = null,
    val repeatCount: Int = 1,
    val isCustomRadius: Boolean = false,
)
