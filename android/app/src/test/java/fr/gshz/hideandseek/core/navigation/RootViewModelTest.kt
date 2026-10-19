package fr.gshz.hideandseek.core.navigation

import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.util.ERROR_KEY_PLAYER_LEFT
import fr.gshz.hideandseek.domain.model.JoinResult
import fr.gshz.hideandseek.domain.repository.ConnectionRepository
import fr.gshz.hideandseek.fake.FakeGameRepository
import fr.gshz.hideandseek.fake.FakeSessionRepository
import fr.gshz.hideandseek.fake.httpException
import io.mockk.coEvery
import io.mockk.every
import io.mockk.mockk
import java.io.IOException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class RootViewModelTest {

    private val connectionRepository = mockk<ConnectionRepository>()
    private val sessionRepository = FakeSessionRepository()
    private val gameRepository = FakeGameRepository()
    private val navigationRequestStore = NavigationRequestStore()

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(UnconfinedTestDispatcher())
        every { connectionRepository.observeConnection() } returns
            flowOf(ConnectionConfig("https://example.test", "api-key"))
        coEvery { connectionRepository.playerName() } returns "Alice"
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    private fun createViewModel() = RootViewModel(
        connectionRepository,
        sessionRepository,
        gameRepository,
        navigationRequestStore,
    )

    private fun session() = PlayerSession(
        gameUuid = "game-1",
        roundUuid = "round-1",
        playerUuid = "player-1",
        displayName = "Alice",
        mercureToken = "token",
        side = null,
    )

    @Test
    fun `a silent rejoin with the player name returns Rejoined and saves the session`() = runTest {
        sessionRepository.seed(session())
        gameRepository.joinGameResult = Result.success(
            JoinResult("player-1", "Alice", "game-1", "round-1", "token", listOf("t")),
        )

        val outcome = createViewModel().handleSessionExpired()

        assertTrue(outcome is SessionExpiredOutcome.Rejoined)
        assertEquals("game-1", (outcome as SessionExpiredOutcome.Rejoined).gameUuid)
        assertEquals("game-1", sessionRepository.currentSession()?.gameUuid)
        assertEquals("game-1", gameRepository.lastJoinGameUuid)
        assertEquals("Alice", gameRepository.lastJoinDisplayName)
        assertNull(navigationRequestStore.sessionExpiredRequest.value)
    }

    @Test
    fun `a silent rejoin session never carries location topics`() = runTest {
        sessionRepository.seed(session())
        gameRepository.joinGameResult = Result.success(
            JoinResult(
                "player-1", "Alice", "game-1", "round-1", "token",
                listOf("game/game-1/chat", "game/game-1/round/round-1/seeker-locations"),
            ),
        )

        createViewModel().handleSessionExpired()

        val topics = sessionRepository.currentSession()?.topics ?: emptyList()
        assertTrue(topics.none { it.endsWith("-locations") })
        assertTrue(topics.contains("game/game-1/chat"))
    }

    @Test
    fun `a player left rejection skips the silent rejoin and keeps the error key`() = runTest {
        sessionRepository.seed(session())
        gameRepository.joinGameResult = Result.success(
            JoinResult("player-1", "Alice", "game-1", "round-1", "token", listOf("t")),
        )

        val outcome = createViewModel().handleSessionExpired(ERROR_KEY_PLAYER_LEFT)

        assertEquals(SessionExpiredOutcome.NeedsJoin("game-1", ERROR_KEY_PLAYER_LEFT), outcome)
        assertNull(gameRepository.lastJoinDisplayName)
        assertNull(sessionRepository.currentSession())
    }

    @Test
    fun `without a player name the user lands on the join screen blank`() = runTest {
        coEvery { connectionRepository.playerName() } returns null
        sessionRepository.seed(session())

        val outcome = createViewModel().handleSessionExpired()

        assertEquals(SessionExpiredOutcome.NeedsJoin("game-1", null), outcome)
        assertNull(gameRepository.lastJoinDisplayName)
        assertNull(sessionRepository.currentSession())
    }

    @Test
    fun `a rejected device sends the user back with the error key`() = runTest {
        sessionRepository.seed(session())
        gameRepository.joinGameResult =
            Result.failure(httpException(400, """{"errorKey":"join.device_mismatch"}"""))

        val outcome = createViewModel().handleSessionExpired()

        assertEquals(SessionExpiredOutcome.NeedsJoin("game-1", "join.device_mismatch"), outcome)
        assertNull(sessionRepository.currentSession())
    }

    @Test
    fun `a deleted game 404 sends the user to the join screen without an error key`() = runTest {
        sessionRepository.seed(session())
        gameRepository.joinGameResult = Result.failure(httpException(404))

        val outcome = createViewModel().handleSessionExpired()

        assertEquals(SessionExpiredOutcome.NeedsJoin("game-1", null), outcome)
    }

    @Test
    fun `an offline rejoin sends the user to the join screen without an error key`() = runTest {
        sessionRepository.seed(session())
        gameRepository.joinGameResult = Result.failure(IOException())

        val outcome = createViewModel().handleSessionExpired()

        assertEquals(SessionExpiredOutcome.NeedsJoin("game-1", null), outcome)
    }

    @Test
    fun `start destination is connect without a connection`() = runTest {
        every { connectionRepository.observeConnection() } returns flowOf(null)

        val viewModel = createViewModel()

        assertEquals(HideAndSeekDestinations.CONNECT, viewModel.startDestination.value)
    }

    @Test
    fun `start destination is connect when the connection has no player name`() = runTest {
        coEvery { connectionRepository.playerName() } returns null

        val viewModel = createViewModel()

        assertEquals(HideAndSeekDestinations.CONNECT, viewModel.startDestination.value)
    }

    @Test
    fun `start destination is home with a player name and no session`() = runTest {
        val viewModel = createViewModel()

        assertEquals(HideAndSeekDestinations.HOME, viewModel.startDestination.value)
    }

    @Test
    fun `start destination is the lobby of the current session`() = runTest {
        sessionRepository.seed(session())

        val viewModel = createViewModel()

        assertEquals(HideAndSeekDestinations.lobbyRoute("game-1"), viewModel.startDestination.value)
    }
}
