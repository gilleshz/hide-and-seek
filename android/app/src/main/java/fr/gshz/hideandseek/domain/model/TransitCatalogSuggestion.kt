package fr.gshz.hideandseek.domain.model

data class TransitCatalogSuggestion(
    val id: String,
    val provider: String,
    val name: String,
    val municipality: String,
    val countryCode: String,
    val licenseUrl: String,
    val downloadUrl: String,
    val fallbackUrl: String,
    val realtimeUrl: String,
    val live: Boolean,
    val scope: String,
)
