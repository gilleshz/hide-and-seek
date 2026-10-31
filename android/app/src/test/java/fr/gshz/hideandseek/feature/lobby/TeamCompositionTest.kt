package fr.gshz.hideandseek.feature.lobby

import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.model.Side
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Test

class TeamCompositionTest {

    @Test
    fun `lists the names on each side in roster order`() {
        val roster = listOf(
            Player("1", "Alice", Side.Seeker),
            Player("2", "Bob", Side.Hider),
            Player("3", "Carol", Side.Seeker),
            Player("4", "Dave"),
        )

        assertEquals("Bob", teamNames(roster, Side.Hider))
        assertEquals("Alice, Carol", teamNames(roster, Side.Seeker))
    }

    @Test
    fun `returns null for a side without players`() {
        assertNull(teamNames(emptyList(), Side.Hider))
        assertNull(teamNames(listOf(Player("1", "Alice", Side.Seeker)), Side.Hider))
    }
}
