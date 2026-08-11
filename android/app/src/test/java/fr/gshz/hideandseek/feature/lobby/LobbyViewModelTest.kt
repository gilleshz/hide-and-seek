package fr.gshz.hideandseek.feature.lobby

import androidx.lifecycle.SavedStateHandle
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.core.navigation.NavigationRequestStore
import fr.gshz.hideandseek.core.util.ERROR_KEY_PLAYER_LEFT
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.GameSummary
import fr.gshz.hideandseek.domain.model.LeaderboardEntry
import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.model.TeamResult
import fr.gshz.hideandseek.domain.repository.TimerEvent
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.data.GameStateCache
import fr.gshz.hideandseek.fake.FakeGameEventRepository
import fr.gshz.hideandseek.fake.FakeGameRepository
import fr.gshz.hideandseek.fake.FakeRoundRepository
import fr.gshz.hideandseek.fake.FakeSessionRepository
import fr.gshz.hideandseek.fake.httpException
import io.mockk.coEvery
import io.mockk.mockk
import java.io.IOException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import retrofit2.HttpException
import retrofit2.Response

@OptIn(ExperimentalCoroutinesApi::class)
@Suppress("LargeClass")
class LobbyViewModelTest {

    private val gameRepository = FakeGameRepository()
    private val sessionRepository = FakeSessionRepository()
    private val roundRepository = FakeRoundRepository()
    private val connectionStore = mockk<ConnectionStore> {
        coEvery { current() } returns null
    }
    private val clientConfigRepository =
        mockk<fr.gshz.hideandseek.domain.repository.ClientConfigRepository>(relaxed = true)
    private val gameStateCache = GameStateCache(gameRepository, clientConfigRepository)
    private val gameEventRepository = FakeGameEventRepository()
    private val navigationRequestStore = NavigationRequestStore()

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(UnconfinedTestDispatcher())
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    private fun createViewModel(gameUuid: String = "game-1") = LobbyViewModel(
        gameRepository,
        sessionRepository,
        roundRepository,
        connectionStore,
        gameStateCache,
        navigationRequestStore,
        gameEventRepository,
        SavedStateHandle(mapOf(HideAndSeekDestinations.LOBBY_ARG to gameUuid)),
    )

    @Test
    fun `loads game info and roster on init`() = runTest {
        gameRepository.getGameResult =
            Result.success(GameSummary("game-1", "Berlin", GameSize.Large, Edition.Imperial, "round-1"))
        gameRepository.listPlayersResult = Result.success(listOf(Player("p1", "Alice")))

        val viewModel = createViewModel()

        val state = viewModel.uiState.value
        assertEquals("game-1", state.gameUuid)
        assertEquals("Berlin", state.gameName)
        assertEquals(GameSize.Large, state.gameSize)
        assertEquals(1, state.roster.size)
        assertFalse(state.isLoading)
        assertNull(state.error)
    }

    @Test
    fun `choosing a side calls the API and persists it in the session`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null))
        gameRepository.chooseTeamResult =
            Result.success(TeamResult("player-1", "round-1", Side.Hider, "token", listOf("team")))

        val viewModel = createViewModel()
        viewModel.chooseSide(Side.Hider)

        assertEquals(Side.Hider, viewModel.uiState.value.mySide)
        assertEquals(Side.Hider, sessionRepository.updatedSide)
    }

    @Test
    fun `a lobby round exposes the start-round control`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"))
        roundRepository.round = roundRepository.round.copy(status = RoundStatus.Lobby)

        val viewModel = createViewModel()

        assertEquals(RoundStatus.Lobby, viewModel.uiState.value.roundStatus)
        assertTrue(viewModel.uiState.value.canStartRound)
    }

    @Test
    fun `starting the round calls the repository and advances the status out of lobby`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"))
        gameRepository.listPlayersResult = Result.success(listOf(Player("player-1", "Alice", Side.Hider)))

        val viewModel = createViewModel()
        viewModel.startRound()

        assertEquals(listOf("round-1"), roundRepository.startCalls)
        assertEquals(RoundStatus.Hiding, viewModel.uiState.value.roundStatus)
        assertFalse(viewModel.uiState.value.canStartRound)
    }

    @Test
    fun `starting the round is blocked while a player has no side`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"))
        roundRepository.round = roundRepository.round.copy(status = RoundStatus.Lobby)
        gameRepository.listPlayersResult = Result.success(
            listOf(Player("player-1", "Alice", Side.Hider), Player("player-2", "Bob", side = null)),
        )

        val viewModel = createViewModel()
        assertFalse(viewModel.uiState.value.allPlayersChoseSide)

        viewModel.startRound()

        assertTrue(roundRepository.startCalls.isEmpty())
        assertEquals(RoundStatus.Lobby, viewModel.uiState.value.roundStatus)
    }

    @Test
    fun `lobby load prefills the hiding time from the game default`() = runTest {
        gameRepository.getGameResult = Result.success(
            GameSummary(
                "game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-1",
                defaultHidingPeriodMinutes = 60,
            ),
        )

        val viewModel = createViewModel()

        assertEquals("60", viewModel.uiState.value.hidingTimeMinutesInput)
    }

    @Test
    fun `reloading the lobby keeps a user-edited hiding time`() = runTest {
        gameRepository.getGameResult = Result.success(
            GameSummary(
                "game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-1",
                defaultHidingPeriodMinutes = 60,
            ),
        )

        val viewModel = createViewModel()
        viewModel.onHidingTimeChanged("35")
        viewModel.load()

        assertEquals("35", viewModel.uiState.value.hidingTimeMinutesInput)
    }

    @Test
    fun `starting the round sends the edited hiding time`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"))
        gameRepository.listPlayersResult = Result.success(listOf(Player("player-1", "Alice", Side.Hider)))

        val viewModel = createViewModel()
        viewModel.onHidingTimeChanged("35")
        viewModel.startRound()

        assertEquals(35, roundRepository.lastStartHidingMinutes)
    }

    @Test
    fun `starting the round with a blank hiding time sends null`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"))
        gameRepository.listPlayersResult = Result.success(listOf(Player("player-1", "Alice", Side.Hider)))
        roundRepository.lastStartHidingMinutes = -1

        val viewModel = createViewModel()
        viewModel.startRound()

        assertEquals(listOf("round-1"), roundRepository.startCalls)
        assertNull(roundRepository.lastStartHidingMinutes)
    }

    @Test
    fun `hiding time input drops non-digits and caps the length`() = runTest {
        val viewModel = createViewModel()

        viewModel.onHidingTimeChanged("1a2b")
        assertEquals("12", viewModel.uiState.value.hidingTimeMinutesInput)

        viewModel.onHidingTimeChanged("123456")
        assertEquals("1234", viewModel.uiState.value.hidingTimeMinutesInput)
    }

    @Test
    fun `team pick failure surfaces a network error`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null))
        gameRepository.chooseTeamResult = Result.failure(IOException())

        val viewModel = createViewModel()
        viewModel.chooseSide(Side.Seeker)

        assertEquals(ErrorType.Network, viewModel.uiState.value.error)
        assertNull(viewModel.uiState.value.mySide)
    }

    @Test
    fun `team pick rejection surfaces the server's reason instead of a generic message`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null))
        val reason = "Only the seeker side can be joined once the round has left the lobby."
        val body = """{"detail":"$reason"}""".toResponseBody("application/problem+json".toMediaType())
        gameRepository.chooseTeamResult = Result.failure(HttpException(Response.error<Unit>(400, body)))

        val viewModel = createViewModel()
        viewModel.chooseSide(Side.Hider)

        assertEquals(ErrorType.Validation, viewModel.uiState.value.error)
        assertEquals(reason, viewModel.uiState.value.errorDetail)
        assertNull(viewModel.uiState.value.mySide)
    }

    @Test
    fun `a later action clears a previous server error detail`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null))
        val body = """{"detail":"nope"}""".toResponseBody("application/problem+json".toMediaType())
        gameRepository.chooseTeamResult = Result.failure(HttpException(Response.error<Unit>(400, body)))

        val viewModel = createViewModel()
        viewModel.chooseSide(Side.Hider)
        assertEquals("nope", viewModel.uiState.value.errorDetail)

        gameRepository.chooseTeamResult =
            Result.success(TeamResult("player-1", "round-1", Side.Hider, "token", listOf("team")))
        viewModel.chooseSide(Side.Hider)

        assertNull(viewModel.uiState.value.error)
        assertNull(viewModel.uiState.value.errorDetail)
    }

    @Test
    fun `lobby load clears the side when the player has no membership in the new round`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-0", "player-1", "Alice", "token", side = "hider"))
        gameRepository.getGameResult =
            Result.success(GameSummary("game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-2"))

        val viewModel = createViewModel()

        assertEquals("round-2", sessionRepository.updatedRoundUuid)
        assertTrue(sessionRepository.sideCleared)
        assertEquals("round-2", sessionRepository.currentSession()?.roundUuid)
        assertNull(viewModel.uiState.value.mySide)
    }

    @Test
    fun `lobby load re-confirms a server-seeded side for the new round instead of clearing it`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-0", "player-1", "Alice", "old-token", side = "hider"))
        gameRepository.getGameResult =
            Result.success(GameSummary("game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-2"))
        gameRepository.listPlayersResult = Result.success(listOf(Player("player-1", "Alice", side = Side.Seeker)))
        gameRepository.chooseTeamResult =
            Result.success(TeamResult("player-1", "round-2", Side.Seeker, "fresh-token", listOf("topic")))

        val viewModel = createViewModel()

        assertEquals("round-2", sessionRepository.updatedRoundUuid)
        assertFalse(sessionRepository.sideCleared)
        assertEquals(Side.Seeker, sessionRepository.updatedSide)
        assertEquals("fresh-token", sessionRepository.updatedMercureToken)
        assertEquals(Side.Seeker, viewModel.uiState.value.mySide)
    }

    @Test
    fun `lobby load keeps the persisted round and side when they match the server`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"))

        val viewModel = createViewModel()

        assertNull(sessionRepository.updatedRoundUuid)
        assertFalse(sessionRepository.sideCleared)
        assertEquals(Side.Hider, viewModel.uiState.value.mySide)
    }

    @Test
    fun `creating a new round persists the new round uuid and clears the side without a membership`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"))

        val viewModel = createViewModel()
        viewModel.createNewRound()

        assertEquals("round-2", sessionRepository.updatedRoundUuid)
        assertTrue(sessionRepository.sideCleared)
        assertNull(sessionRepository.currentSession()?.side)
        assertNull(viewModel.uiState.value.mySide)
    }

    @Test
    fun `creating a new round re-confirms the seeded swapped side for the host`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"))
        gameRepository.listPlayersResult = Result.success(listOf(Player("player-1", "Alice", side = Side.Seeker)))
        gameRepository.chooseTeamResult =
            Result.success(TeamResult("player-1", "round-2", Side.Seeker, "fresh-token", listOf("topic")))

        val viewModel = createViewModel()
        viewModel.createNewRound()

        assertEquals("round-2", sessionRepository.updatedRoundUuid)
        assertFalse(sessionRepository.sideCleared)
        assertEquals("seeker", sessionRepository.currentSession()?.side)
        assertEquals(Side.Seeker, viewModel.uiState.value.mySide)
    }

    @Test
    fun `stopping the round calls the repository and marks the round ended`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"))
        roundRepository.round = roundRepository.round.copy(status = RoundStatus.Seeking)

        val viewModel = createViewModel()
        viewModel.stopRound()

        assertEquals(listOf("round-1"), roundRepository.stopCalls)
        assertEquals(RoundStatus.Ended, viewModel.uiState.value.roundStatus)
        assertFalse(viewModel.uiState.value.isStoppingRound)
        assertNull(sessionRepository.updatedRoundUuid)
        assertFalse(sessionRepository.sideCleared)
    }

    @Test
    fun `stop round failure surfaces a network error and keeps the round running`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"))
        roundRepository.round = roundRepository.round.copy(status = RoundStatus.Hiding)
        roundRepository.stopResult = Result.failure(IOException())

        val viewModel = createViewModel()
        viewModel.stopRound()

        assertEquals(ErrorType.Network, viewModel.uiState.value.error)
        assertEquals(RoundStatus.Hiding, viewModel.uiState.value.roundStatus)
        assertFalse(viewModel.uiState.value.isStoppingRound)
    }

    @Test
    fun `roster event from another device is applied without refetching the roster`() = runTest {
        gameRepository.listPlayersResult = Result.success(listOf(Player("p1", "Alice")))

        val viewModel = createViewModel()
        assertEquals(1, viewModel.uiState.value.roster.size)
        val callsAfterLoad = gameRepository.listPlayersCalls

        gameEventRepository.emitRosterChanged(
            listOf(Player("p1", "Alice"), Player("p2", "Bob", side = Side.Seeker)),
        )

        val roster = viewModel.uiState.value.roster
        assertEquals(2, roster.size)
        assertEquals("Bob", roster[1].displayName)
        assertEquals(Side.Seeker, roster[1].side)
        assertEquals(callsAfterLoad, gameRepository.listPlayersCalls)
    }

    @Test
    fun `reconnecting refreshes the roster after a missed event`() = runTest {
        gameRepository.listPlayersResult = Result.success(listOf(Player("p1", "Alice")))

        val viewModel = createViewModel()
        assertEquals(1, viewModel.uiState.value.roster.size)

        gameRepository.listPlayersResult = Result.success(listOf(Player("p1", "Alice"), Player("p2", "Bob")))
        gameEventRepository.emitReconnected()

        assertEquals(2, viewModel.uiState.value.roster.size)
    }

    @Test
    fun `returning to the lobby refreshes the roster`() = runTest {
        gameRepository.listPlayersResult = Result.success(listOf(Player("p1", "Alice")))

        val viewModel = createViewModel()
        assertEquals(1, viewModel.uiState.value.roster.size)

        gameRepository.listPlayersResult = Result.success(listOf(Player("p1", "Alice"), Player("p2", "Bob")))
        viewModel.onScreenResumed()

        assertEquals(2, viewModel.uiState.value.roster.size)
    }

    @Test
    fun `timer event from another device updates the round status`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"))
        roundRepository.round = roundRepository.round.copy(status = RoundStatus.Hiding)

        val viewModel = createViewModel()
        assertEquals(RoundStatus.Hiding, viewModel.uiState.value.roundStatus)

        gameEventRepository.emitTimerEvent(
            TimerEvent(status = "ended", hidingPeriodEndsAt = null, seekingEndedAt = null, roundUuid = "round-1"),
        )

        assertEquals(RoundStatus.Ended, viewModel.uiState.value.roundStatus)
    }

    @Test
    fun `a timer event for a new round resyncs and adopts it`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"))
        gameRepository.getGameResult =
            Result.success(GameSummary("game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-1"))

        val viewModel = createViewModel()
        assertEquals(Side.Hider, viewModel.uiState.value.mySide)

        gameRepository.getGameResult =
            Result.success(GameSummary("game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-2"))
        gameRepository.listPlayersResult = Result.success(listOf(Player("player-1", "Alice")))
        gameEventRepository.emitTimerEvent(
            TimerEvent(status = "lobby", hidingPeriodEndsAt = null, seekingEndedAt = null, roundUuid = "round-2"),
        )

        assertEquals("round-2", sessionRepository.updatedRoundUuid)
        assertTrue(sessionRepository.sideCleared)
        assertNull(viewModel.uiState.value.mySide)
    }

    @Test
    fun `returning to the lobby adopts a round whose timer event was missed`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"))
        gameRepository.getGameResult =
            Result.success(GameSummary("game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-1"))
        val viewModel = createViewModel()
        assertEquals(Side.Hider, viewModel.uiState.value.mySide)

        gameRepository.getGameResult =
            Result.success(GameSummary("game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-2"))
        gameRepository.listPlayersResult = Result.success(listOf(Player("player-1", "Alice", side = Side.Seeker)))
        gameRepository.chooseTeamResult =
            Result.success(TeamResult("player-1", "round-2", Side.Seeker, "fresh-token", listOf("topic")))
        viewModel.onScreenResumed()

        assertEquals("round-2", sessionRepository.updatedRoundUuid)
        assertEquals(Side.Seeker, viewModel.uiState.value.mySide)
    }

    @Test
    fun `reconnecting adopts a round whose timer event died with the stream`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"))
        gameRepository.getGameResult =
            Result.success(GameSummary("game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-1"))
        val viewModel = createViewModel()
        assertEquals(Side.Hider, viewModel.uiState.value.mySide)

        gameRepository.getGameResult =
            Result.success(GameSummary("game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-2"))
        gameRepository.listPlayersResult = Result.success(listOf(Player("player-1", "Alice", side = Side.Seeker)))
        gameRepository.chooseTeamResult =
            Result.success(TeamResult("player-1", "round-2", Side.Seeker, "fresh-token", listOf("topic")))
        gameEventRepository.emitReconnected()

        assertEquals("round-2", sessionRepository.updatedRoundUuid)
        assertEquals(Side.Seeker, viewModel.uiState.value.mySide)
    }

    @Test
    fun `returning to the lobby on the same round only refreshes the roster`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"))
        gameRepository.getGameResult =
            Result.success(GameSummary("game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-1"))
        gameRepository.listPlayersResult = Result.success(listOf(Player("player-1", "Alice", side = Side.Hider)))
        val viewModel = createViewModel()

        gameRepository.listPlayersResult = Result.success(
            listOf(Player("player-1", "Alice", side = Side.Hider), Player("p2", "Bob", side = Side.Seeker)),
        )
        viewModel.onScreenResumed()

        assertEquals(2, viewModel.uiState.value.roster.size)
        assertNull(sessionRepository.updatedRoundUuid)
        assertFalse(sessionRepository.sideCleared)
    }

    @Test
    fun `long pressing a roster row opens the player menu for the host`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null))
        gameRepository.listPlayersResult = Result.success(
            listOf(Player("player-1", "Alice", Side.Seeker), Player("player-2", "Bob")),
        )
        val viewModel = createViewModel()
        assertTrue(viewModel.uiState.value.isHost)

        viewModel.onPlayerLongPress(Player("player-2", "Bob"))

        assertEquals("Bob", viewModel.uiState.value.playerMenuTarget?.displayName)
    }

    @Test
    fun `long pressing a roster row is ignored for a non-host`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-2", "Bob", "token", side = null))
        gameRepository.listPlayersResult = Result.success(
            listOf(Player("player-1", "Alice", Side.Seeker), Player("player-2", "Bob")),
        )
        val viewModel = createViewModel()
        assertFalse(viewModel.uiState.value.isHost)

        viewModel.onPlayerLongPress(Player("player-1", "Alice"))

        assertNull(viewModel.uiState.value.playerMenuTarget)
    }

    @Test
    fun `dismissing the player menu clears the target`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null))
        gameRepository.listPlayersResult = Result.success(
            listOf(Player("player-1", "Alice", Side.Seeker), Player("player-2", "Bob")),
        )
        val viewModel = createViewModel()

        viewModel.onPlayerLongPress(Player("player-2", "Bob"))
        viewModel.dismissPlayerMenu()

        assertNull(viewModel.uiState.value.playerMenuTarget)
    }

    @Test
    fun `a roster event without the session player emits the player-left expiry`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null))
        gameRepository.getGameResult =
            Result.success(GameSummary("game-1", "Berlin", GameSize.Large, Edition.Imperial, "round-1"))
        gameRepository.listPlayersResult = Result.success(
            listOf(Player("player-1", "Alice", Side.Seeker), Player("player-2", "Bob")),
        )
        val viewModel = createViewModel()

        gameEventRepository.emitRosterChanged(listOf(Player("player-2", "Bob")))

        assertEquals(ERROR_KEY_PLAYER_LEFT, navigationRequestStore.sessionExpiredRequest.value)
    }

    @Test
    fun `a roster event without a session does not emit the player-left expiry`() = runTest {
        gameRepository.getGameResult =
            Result.success(GameSummary("game-1", "Berlin", GameSize.Large, Edition.Imperial, "round-1"))
        gameRepository.listPlayersResult = Result.success(listOf(Player("player-2", "Bob")))
        val viewModel = createViewModel()

        gameEventRepository.emitRosterChanged(listOf(Player("player-2", "Bob")))

        assertNull(navigationRequestStore.sessionExpiredRequest.value)
    }

    @Test
    fun `the remove player dialog opens only for the host and closes the menu`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null))
        gameRepository.listPlayersResult = Result.success(
            listOf(Player("player-1", "Alice", Side.Seeker), Player("player-2", "Bob")),
        )
        val viewModel = createViewModel()
        assertTrue(viewModel.uiState.value.isHost)

        viewModel.onRemovePlayerClick(Player("player-2", "Bob"))

        assertEquals("Bob", viewModel.uiState.value.removeConfirmTarget?.displayName)
        assertNull(viewModel.uiState.value.playerMenuTarget)
    }

    @Test
    fun `the remove player dialog stays closed for a non-host`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-2", "Bob", "token", side = null))
        gameRepository.listPlayersResult = Result.success(
            listOf(Player("player-1", "Alice", Side.Seeker), Player("player-2", "Bob")),
        )
        val viewModel = createViewModel()
        assertFalse(viewModel.uiState.value.isHost)

        viewModel.onRemovePlayerClick(Player("player-1", "Alice"))

        assertNull(viewModel.uiState.value.removeConfirmTarget)
    }

    @Test
    fun `confirming the removal sends the request and closes the dialog`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null))
        gameRepository.listPlayersResult = Result.success(
            listOf(Player("player-1", "Alice", Side.Seeker), Player("player-2", "Bob")),
        )
        val viewModel = createViewModel()

        viewModel.onRemovePlayerClick(Player("player-2", "Bob"))
        viewModel.confirmRemovePlayer()

        assertEquals(listOf("game-1" to "player-2"), gameRepository.removePlayerCalls)
        assertNull(viewModel.uiState.value.removeConfirmTarget)
        assertNull(viewModel.uiState.value.removePlayerError)
    }

    @Test
    fun `a failed removal keeps the dialog open with the error`() = runTest {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null))
        gameRepository.listPlayersResult = Result.success(
            listOf(Player("player-1", "Alice", Side.Seeker), Player("player-2", "Bob")),
        )
        gameRepository.removePlayerResult =
            Result.failure(httpException(400, """{"errorKey":"player.remove_not_host"}"""))
        val viewModel = createViewModel()

        viewModel.onRemovePlayerClick(Player("player-2", "Bob"))
        viewModel.confirmRemovePlayer()

        assertEquals("Bob", viewModel.uiState.value.removeConfirmTarget?.displayName)
        assertEquals("player.remove_not_host", viewModel.uiState.value.removePlayerErrorKey)
    }

    @Test
    fun `a scored game exposes the leaderboard in the order the server returned`() = runTest {
        gameRepository.leaderboardResult = Result.success(
            listOf(
                LeaderboardEntry("round-2", 2, listOf("Bob"), 5000L, 5600L, 10, 0),
                LeaderboardEntry("round-1", 1, listOf("Alice", "Carol"), 8048L, 8648L, 0, 5),
            ),
        )

        val viewModel = createViewModel()

        val leaderboard = viewModel.uiState.value.leaderboard
        assertEquals(listOf("round-2", "round-1"), leaderboard.map { it.roundUuid })
        assertEquals(listOf("Alice", "Carol"), leaderboard[1].hiderNames)
        assertEquals(8648L, leaderboard[1].scoreSeconds)
        assertTrue(viewModel.uiState.value.hasLeaderboard)
    }

    @Test
    fun `a game with no scored rounds leaves the leaderboard empty`() = runTest {
        gameRepository.leaderboardResult = Result.success(emptyList())

        val viewModel = createViewModel()

        assertTrue(viewModel.uiState.value.leaderboard.isEmpty())
        assertFalse(viewModel.uiState.value.hasLeaderboard)
    }

    @Test
    fun `a leaderboard failure stays silent and keeps the roster`() = runTest {
        gameRepository.listPlayersResult = Result.success(listOf(Player("p1", "Alice")))
        gameRepository.leaderboardResult = Result.failure(IOException())

        val viewModel = createViewModel()

        val state = viewModel.uiState.value
        assertNull(state.error)
        assertNull(state.errorDetail)
        assertEquals(1, state.roster.size)
        assertTrue(state.leaderboard.isEmpty())
        assertFalse(state.isLoading)
    }

    @Test
    fun `returning to the lobby refreshes the leaderboard`() = runTest {
        val viewModel = createViewModel()
        val callsAfterLoad = gameRepository.leaderboardCalls
        gameRepository.leaderboardResult =
            Result.success(listOf(LeaderboardEntry("round-1", 1, listOf("Alice"), 8048L, 8648L, 0, 0)))

        viewModel.onScreenResumed()

        assertEquals(callsAfterLoad + 1, gameRepository.leaderboardCalls)
        assertEquals(1, viewModel.uiState.value.leaderboard.size)
    }
}
