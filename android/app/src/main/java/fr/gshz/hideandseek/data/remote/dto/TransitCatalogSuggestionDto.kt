package fr.gshz.hideandseek.data.remote.dto

import kotlinx.serialization.Serializable

@Serializable
data class TransitCatalogSuggestionDto(
    val id: String,
    val provider: String,
    val name: String,
    val municipality: String,
    val countryCode: String,
    val licenseUrl: String? = null,
    val downloadUrl: String,
    val fallbackUrl: String? = null,
    val realtimeUrl: String? = null,
    val live: Boolean = false,
    val scope: String = "regional",
)
