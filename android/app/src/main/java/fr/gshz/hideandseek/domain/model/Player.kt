package fr.gshz.hideandseek.domain.model

data class Player(
    val uuid: String,
    val displayName: String,
    val side: Side? = null,
)
