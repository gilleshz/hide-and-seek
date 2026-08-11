package fr.gshz.hideandseek.core.model

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Test

class PlayerSessionTest {

    @Test
    fun `withoutLocationTopics strips only the location suffix`() {
        val topics = listOf(
            "game/g/chat",
            "game/g/round/r/seeker-locations",
            "game/g/round/r/hider-locations",
            "game/g/roster",
        )

        assertEquals(listOf("game/g/chat", "game/g/roster"), topics.withoutLocationTopics())
    }

    @Test
    fun `topicsWithoutLocations delegates to the list helper`() {
        val session = PlayerSession(
            gameUuid = "g",
            roundUuid = "r",
            playerUuid = "p",
            displayName = "n",
            mercureToken = "t",
            side = null,
            topics = listOf("game/g/chat", "game/g/round/r/hider-locations"),
        )

        assertEquals(listOf("game/g/chat"), session.topicsWithoutLocations())
    }
}
