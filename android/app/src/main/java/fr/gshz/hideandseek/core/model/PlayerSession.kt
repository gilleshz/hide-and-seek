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
    // PRIV-*: side-scoped grants; a session without a confirmed side must not subscribe to them.
    fun topicsWithoutSideGrants(): List<String> = topics.withoutSideGrants()

    companion object {
        val SIDE_SCOPED_TOPIC_SUFFIXES = listOf(
            "-locations",
            "/seeker-candidates",
            "/seeker-zone",
        )
    }
}

// PRIV-*: side-scoped grants; a side-less session must not carry them.
fun String.isSideScopedTopic(): Boolean =
    PlayerSession.SIDE_SCOPED_TOPIC_SUFFIXES.any { suffix -> endsWith(suffix) }

fun List<String>.withoutSideGrants(): List<String> = filterNot { it.isSideScopedTopic() }
