package fr.gshz.hideandseek.domain.model

data class TokenRefresh(
    val mercureToken: String,
    val topics: List<String>,
)
