package fr.gshz.hideandseek.domain.model

data class TeamResult(
    val playerUuid: String,
    val roundUuid: String,
    val side: Side,
    val mercureToken: String,
    val topics: List<String>,
)
