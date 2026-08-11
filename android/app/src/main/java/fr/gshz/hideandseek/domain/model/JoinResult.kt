package fr.gshz.hideandseek.domain.model

data class JoinResult(
    val playerUuid: String,
    val displayName: String,
    val gameUuid: String,
    val roundUuid: String,
    val mercureToken: String,
    val topics: List<String>,
)
