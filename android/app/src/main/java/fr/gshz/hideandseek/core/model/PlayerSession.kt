package fr.gshz.hideandseek.core.model

data class PlayerSession(
    val gameUuid: String,
    val roundUuid: String,
    val playerUuid: String,
    val displayName: String,
    val mercureToken: String,
    val side: String?,
    val topics: List<String> = emptyList(),
) {
    // PRIV-*: location topics are side-scoped grants; a session without a confirmed side must not subscribe to them.
    fun topicsWithoutLocations(): List<String> = topics.withoutLocationTopics()

    companion object {
        const val LOCATION_TOPIC_SUFFIX = "-locations"
    }
}

// PRIV-*: location topics are side-scoped grants; a side-less session must not carry them.
fun List<String>.withoutLocationTopics(): List<String> = filterNot { it.endsWith(PlayerSession.LOCATION_TOPIC_SUFFIX) }
