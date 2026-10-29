package fr.gshz.hideandseek.core.model

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class PlayerSessionTest {

    private val baselineTopics = listOf(
        "game/g/roster",
        "game/g/chat",
        "game/g/timer",
        "game/g/round/r/time-traps",
        "game/g/round/r/possible-area",
        "player/p/endgame",
    )

    private val sideTopics = listOf(
        "game/g/round/r/seeker-locations",
        "game/g/round/r/hider-locations",
        "game/g/round/r/seeker-candidates",
        "game/g/round/r/seeker-zone",
    )

    @Test
    fun `withoutSideGrants strips every side-scoped topic and keeps the baseline ones`() {
        assertEquals(baselineTopics, (sideTopics + baselineTopics).withoutSideGrants())
    }

    @Test
    fun `topicsWithoutSideGrants delegates to the list helper`() {
        val session = PlayerSession(
            gameUuid = "g",
            roundUuid = "r",
            playerUuid = "p",
            displayName = "n",
            mercureToken = "t",
            side = null,
            topics = baselineTopics + "game/g/round/r/hider-locations",
        )

        assertEquals(baselineTopics, session.topicsWithoutSideGrants())
    }

    @Test
    fun `the side-scoped predicate recognises each suffixed grant`() {
        assertTrue(sideTopics.all(String::isSideScopedTopic))
        assertFalse(baselineTopics.any(String::isSideScopedTopic))
    }
}
