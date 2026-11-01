package fr.gshz.hideandseek.feature.lobby

import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.Side
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class LobbyUiStateTest {

    @Test
    fun `every role is available in the lobby`() {
        val state = LobbyUiState(roundStatus = RoundStatus.Lobby)

        Side.entries.forEach { assertTrue(state.canChoose(it), "$it should be available") }
        assertFalse(state.showRolesLocked)
    }

    @Test
    fun `a player without a membership mid-round may seek or spectate but not hide`() {
        listOf(RoundStatus.Hiding, RoundStatus.Seeking).forEach { status ->
            val state = LobbyUiState(roundStatus = status)

            assertFalse(state.canChoose(Side.Hider))
            assertTrue(state.canChoose(Side.Seeker))
            assertTrue(state.canChoose(Side.Spectator))
            assertTrue(state.showRolesLocked)
        }
    }

    @Test
    fun `an existing membership mid-round may only re-confirm its own role`() {
        listOf(RoundStatus.Hiding, RoundStatus.Seeking).forEach { status ->
            val hider = LobbyUiState(mySide = Side.Hider, roundStatus = status)
            val spectator = LobbyUiState(mySide = Side.Spectator, roundStatus = status)

            assertTrue(hider.canChoose(Side.Hider))
            assertFalse(hider.canChoose(Side.Seeker))
            assertFalse(hider.canChoose(Side.Spectator))
            assertTrue(spectator.canChoose(Side.Spectator))
            assertFalse(spectator.canChoose(Side.Seeker))
        }
    }

    @Test
    fun `an ended round only re-confirms an existing role`() {
        val withoutMembership = LobbyUiState(roundStatus = RoundStatus.Ended)
        val seeker = LobbyUiState(mySide = Side.Seeker, roundStatus = RoundStatus.Ended)

        Side.entries.forEach { assertFalse(withoutMembership.canChoose(it)) }
        assertTrue(seeker.canChoose(Side.Seeker))
        assertFalse(seeker.canChoose(Side.Hider))
        assertTrue(withoutMembership.showRolesLocked)
    }

    @Test
    fun `a spectator counts as having chosen a side`() {
        val state = LobbyUiState(roster = listOf(Player("p1", "Alice", Side.Spectator)))

        assertTrue(state.allPlayersChoseSide)
    }

    @Test
    fun `a non-host spectator has no round controls`() {
        val spectator = LobbyUiState(mySide = Side.Spectator, roundStatus = RoundStatus.Lobby)

        assertFalse(spectator.canStartRound)
        assertFalse(spectator.copy(roundStatus = RoundStatus.Ended).canCreateNewRound)
        assertFalse(spectator.copy(roundStatus = RoundStatus.Seeking).canStopRound)
    }

    @Test
    fun `a host spectator may start and create rounds`() {
        val host = LobbyUiState(mySide = Side.Spectator, roundStatus = RoundStatus.Lobby, isHost = true)

        assertTrue(host.canStartRound)
        assertTrue(host.copy(roundStatus = RoundStatus.Ended).canCreateNewRound)
        assertFalse(host.copy(roundStatus = RoundStatus.Seeking).canStopRound)
    }

    @Test
    fun `a non-host player keeps the round controls`() {
        val player = LobbyUiState(mySide = Side.Hider, roundStatus = RoundStatus.Lobby)

        assertTrue(player.canStartRound)
        assertTrue(player.copy(roundStatus = RoundStatus.Ended).canCreateNewRound)
        assertTrue(player.copy(roundStatus = RoundStatus.Seeking).canStopRound)
    }
}
