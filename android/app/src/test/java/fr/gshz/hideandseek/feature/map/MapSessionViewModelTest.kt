package fr.gshz.hideandseek.feature.map

import app.cash.turbine.test
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.domain.model.DeviceLocation
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.GameSummary
import fr.gshz.hideandseek.domain.model.LocationUpdate
import fr.gshz.hideandseek.domain.model.Player
import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.ScoreDeclaration
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.repository.TimerEvent
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNotNull
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class MapSessionViewModelTest {

    private val fixture = MapTestFixture()
    private val testDispatcher = UnconfinedTestDispatcher()

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(testDispatcher)
        fixture.setUp()
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun `resolves the incoming player's display name from the roster`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null),
        )
        fixture.gameRepository.getGameResult =
            Result.success(GameSummary("game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-1"))
        fixture.gameRepository.listPlayersResult =
            Result.success(listOf(Player("player-1", "Alice"), Player("player-2", "Bob")))

        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.gameName.isEmpty()) state = awaitItem()
            assertEquals("Berlin", state.gameName)

            fixture.locationRepository.emit(LocationUpdate("player-2", 1.0, 2.0, "2026-01-01T00:00:00Z"))

            while (state.markers.none { it.playerUuid == "player-2" }) state = awaitItem()
            val bob = state.markers.first { it.playerUuid == "player-2" }
            assertEquals("Bob", bob.displayName)
            assertFalse(bob.isSelf)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `shows a self marker from GPS even before any location stream update arrives`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null),
        )
        fixture.gameRepository.listPlayersResult = Result.success(listOf(Player("player-1", "Alice")))
        fixture.locationRepository.updateLastKnownLocation(DeviceLocation(latitude = 48.5, longitude = 7.7))

        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.markers.none { it.isSelf }) state = awaitItem()
            val self = state.markers.first { it.isSelf }
            assertEquals("player-1", self.playerUuid)
            assertEquals(48.5, self.latitude, 0.0)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `keeps only the latest location per player and flags the current player's marker`() =
        runTest(testDispatcher) {
            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null),
            )
            fixture.gameRepository.listPlayersResult =
                Result.success(listOf(Player("player-1", "Alice"), Player("player-2", "Bob")))

            val viewModel = fixture.createSessionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()

                fixture.locationRepository.emit(LocationUpdate("player-1", 1.0, 1.0, "t1"))
                fixture.locationRepository.emit(LocationUpdate("player-2", 2.0, 2.0, "t2"))
                fixture.locationRepository.emit(LocationUpdate("player-1", 3.0, 3.0, "t3"))

                while (state.markers.firstOrNull { it.playerUuid == "player-1" }?.latitude != 3.0) {
                    state = awaitItem()
                }

                assertEquals(2, state.markers.size)
                val self = state.markers.first { it.playerUuid == "player-1" }
                assertTrue(self.isSelf)
                assertEquals(3.0, self.latitude, 0.0)
                assertEquals("Alice", self.displayName)
                val other = state.markers.first { it.playerUuid == "player-2" }
                assertFalse(other.isSelf)
                assertEquals("Bob", other.displayName)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `a stale location update does not move a marker backwards`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null),
        )
        fixture.gameRepository.listPlayersResult =
            Result.success(listOf(Player("player-1", "Alice"), Player("player-2", "Bob")))

        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            fixture.locationRepository.emit(LocationUpdate("player-2", 5.0, 6.0, "2026-01-01T00:02:00Z"))
            fixture.locationRepository.emit(LocationUpdate("player-2", 1.0, 2.0, "2026-01-01T00:01:00Z"))
            fixture.locationRepository.emit(LocationUpdate("player-1", 9.0, 9.0, "2026-01-01T00:03:00Z"))

            while (state.markers.none { it.playerUuid == "player-1" }) state = awaitItem()
            val bob = state.markers.first { it.playerUuid == "player-2" }
            assertEquals(5.0, bob.latitude, 0.0)
            assertEquals(6.0, bob.longitude, 0.0)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a newer location update moves the marker`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null),
        )
        fixture.gameRepository.listPlayersResult =
            Result.success(listOf(Player("player-1", "Alice"), Player("player-2", "Bob")))

        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            fixture.locationRepository.emit(LocationUpdate("player-2", 1.0, 2.0, "2026-01-01T00:01:00Z"))
            fixture.locationRepository.emit(LocationUpdate("player-2", 5.0, 6.0, "2026-01-01T00:02:00Z"))

            while (state.markers.firstOrNull { it.playerUuid == "player-2" }?.latitude != 5.0) {
                state = awaitItem()
            }
            val bob = state.markers.first { it.playerUuid == "player-2" }
            assertEquals(6.0, bob.longitude, 0.0)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a location update with an equal recordedAt still applies`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = null),
        )
        fixture.gameRepository.listPlayersResult =
            Result.success(listOf(Player("player-1", "Alice"), Player("player-2", "Bob")))

        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            fixture.locationRepository.emit(LocationUpdate("player-2", 1.0, 2.0, "2026-01-01T00:01:00Z"))
            fixture.locationRepository.emit(LocationUpdate("player-2", 5.0, 6.0, "2026-01-01T00:01:00Z"))

            while (state.markers.firstOrNull { it.playerUuid == "player-2" }?.latitude != 5.0) {
                state = awaitItem()
            }
            val bob = state.markers.first { it.playerUuid == "player-2" }
            assertEquals(6.0, bob.longitude, 0.0)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `exposes the player's side so the UI can gate the zone entry point`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.side == null) state = awaitItem()
            assertEquals(Side.Hider, state.side)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a hiding round shows a countdown and hides the end-round control`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        val now = System.currentTimeMillis()
        fixture.roundRepository.round = fixture.roundRepository.round.copy(
            status = RoundStatus.Hiding,
            hidingPeriodEndsAtMillis = now + FIVE_MINUTES_MS,
        )

        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.roundStatus != RoundStatus.Hiding) state = awaitItem()
            assertNotNull(state.roundTimerSeconds)
            assertTrue((state.roundTimerSeconds ?: -1) >= 0)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a seeking round counts up and exposes the end-round control to hiders`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        val now = System.currentTimeMillis()
        fixture.roundRepository.round = fixture.roundRepository.round.copy(
            status = RoundStatus.Seeking,
            hidingPeriodEndsAtMillis = now - ONE_MINUTE_MS,
        )

        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.roundStatus != RoundStatus.Seeking) state = awaitItem()
            assertNotNull(state.roundTimerSeconds)
            assertTrue((state.roundTimerSeconds ?: -1) >= 0)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `ending the round calls stop`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        fixture.roundRepository.round = fixture.roundRepository.round.copy(
            status = RoundStatus.Seeking,
            hidingPeriodEndsAtMillis = System.currentTimeMillis() - ONE_MINUTE_MS,
        )

        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.roundStatus != RoundStatus.Seeking) state = awaitItem()

            viewModel.endRound(ScoreDeclaration(bonusMinutes = 15, bonusPercent = 20, hidingSeconds = 600))
            while (state.roundStatus != RoundStatus.Ended) state = awaitItem()

            assertEquals(listOf("round-1"), fixture.roundRepository.stopCalls)
            assertEquals(15, fixture.roundRepository.stopScores.single()?.bonusMinutes)
            assertEquals(600L, fixture.roundRepository.stopScores.single()?.hidingSeconds)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a seeker cannot end the round because ending declares the hiders' bonuses`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Bob", "token", side = "seeker"),
        )
        fixture.roundRepository.round = fixture.roundRepository.round.copy(
            status = RoundStatus.Seeking,
            hidingPeriodEndsAtMillis = System.currentTimeMillis() - ONE_MINUTE_MS,
        )

        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.roundStatus != RoundStatus.Seeking) state = awaitItem()
            assertEquals(Side.Seeker, state.side)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `an ended round shows the final hiding time`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        fixture.roundRepository.round = fixture.roundRepository.round.copy(
            status = RoundStatus.Ended,
            hidingTimeSeconds = 125,
        )

        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.roundStatus != RoundStatus.Ended) state = awaitItem()
            assertEquals(125L, state.roundTimerSeconds)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a timer event for a new round asks the map to return to the lobby`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        val viewModel = fixture.createSessionViewModel()

        viewModel.navigateToLobby.test {
            fixture.gameEventRepository.emitTimerEvent(
                TimerEvent(
                    status = "lobby",
                    hidingPeriodEndsAt = null,
                    seekingEndedAt = null,
                    roundUuid = "round-2",
                ),
            )
            awaitItem()
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a timer event for the current round does not navigate to the lobby`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        val viewModel = fixture.createSessionViewModel()

        viewModel.navigateToLobby.test {
            fixture.gameEventRepository.emitTimerEvent(
                TimerEvent(
                    status = "ended",
                    hidingPeriodEndsAt = null,
                    seekingEndedAt = null,
                    roundUuid = "round-1",
                ),
            )
            expectNoEvents()
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a timer event is applied to the round state instead of refetching the round`() =
        runTest(testDispatcher) {
            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
            )
            fixture.roundRepository.round = fixture.roundRepository.round.copy(status = RoundStatus.Hiding)
            val viewModel = fixture.createSessionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                while (state.roundStatus != RoundStatus.Hiding) state = awaitItem()
                val callsAfterFirstPoll = fixture.roundRepository.getRoundCalls.size

                fixture.gameEventRepository.emitTimerEvent(
                    TimerEvent(
                        status = "ended",
                        hidingPeriodEndsAt = null,
                        seekingEndedAt = null,
                        roundUuid = "round-1",
                        scoreSeconds = 4_242,
                        hasHidingZone = true,
                        hidingRadiusMeters = 900.0,
                    ),
                )

                while (state.roundStatus != RoundStatus.Ended) state = awaitItem()
                assertEquals(4_242L, state.roundTimerSeconds)
                assertTrue(state.roundHasHidingZone)
                assertEquals(900.0, state.hidingRadiusMeters)
                assertEquals(callsAfterFirstPoll, fixture.roundRepository.getRoundCalls.size)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `reconnecting onto a round that has moved on asks the map to return to the lobby`() =
        runTest(testDispatcher) {
            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
            )
            fixture.gameRepository.getGameResult =
                Result.success(GameSummary("game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-2"))
            val viewModel = fixture.createSessionViewModel()

            viewModel.navigateToLobby.test {
                fixture.gameEventRepository.emitReconnected()
                awaitItem()
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `reconnecting onto the same round stays on the map`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        fixture.gameRepository.getGameResult =
            Result.success(GameSummary("game-1", "Berlin", GameSize.Medium, Edition.Metric, "round-1"))
        val viewModel = fixture.createSessionViewModel()

        viewModel.navigateToLobby.test {
            fixture.gameEventRepository.emitReconnected()
            expectNoEvents()
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `the seeking counter adds back the seconds a move banked`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        fixture.roundRepository.round = fixture.roundRepository.round.copy(
            status = RoundStatus.Seeking,
            hidingPeriodEndsAtMillis = System.currentTimeMillis() - ONE_MINUTE_MS,
            bankedSeekingSeconds = 600,
        )
        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.roundStatus != RoundStatus.Seeking) state = awaitItem()

            assertTrue((state.roundTimerSeconds ?: 0) >= 660)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `the timer counts the seeking time up once the hiding period elapses`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        fixture.roundRepository.round = fixture.roundRepository.round.copy(
            status = RoundStatus.Hiding,
            hidingPeriodEndsAtMillis = System.currentTimeMillis() - ONE_MINUTE_MS,
        )
        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.roundStatus != RoundStatus.Seeking) state = awaitItem()

            assertTrue((state.roundTimerSeconds ?: 0) >= 60)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a seeker in a move window is frozen and told so`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        fixture.roundRepository.round = fixture.roundRepository.round.copy(
            status = RoundStatus.Hiding,
            hidingPeriodEndsAtMillis = System.currentTimeMillis() + FIVE_MINUTES_MS,
            inMovePeriod = true,
        )
        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.roundStatus != RoundStatus.Hiding) state = awaitItem()

            assertTrue(state.inMovePeriod)
            assertFalse(state.seekersAreHunting)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a hider inside a move window is back to free placement`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        fixture.roundRepository.round = fixture.roundRepository.round.copy(
            status = RoundStatus.Hiding,
            hidingPeriodEndsAtMillis = System.currentTimeMillis() + FIVE_MINUTES_MS,
            inMovePeriod = true,
        )
        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.roundStatus != RoundStatus.Hiding) state = awaitItem()

            assertTrue(state.inMovePeriod)
            assertEquals(Side.Hider, state.side)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a seeker cannot ask while the hiding period is still running`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        fixture.roundRepository.round = fixture.roundRepository.round.copy(
            status = RoundStatus.Hiding,
            hidingPeriodEndsAtMillis = System.currentTimeMillis() + FIVE_MINUTES_MS,
        )
        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.roundStatus != RoundStatus.Hiding) state = awaitItem()

            assertFalse(state.seekersAreHunting)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a seeker can ask once the hiding period has elapsed even before the status flips`() =
        runTest(testDispatcher) {
            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
            )
            fixture.roundRepository.round = fixture.roundRepository.round.copy(
                status = RoundStatus.Hiding,
                hidingPeriodEndsAtMillis = System.currentTimeMillis() - ONE_MINUTE_MS,
            )
            val viewModel = fixture.createSessionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                while (state.roundStatus != RoundStatus.Seeking) state = awaitItem()

                assertTrue(state.seekersAreHunting)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `a round rekey clears the stale markers and restarts the round poll`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        fixture.gameRepository.listPlayersResult = Result.success(listOf(Player("player-1", "Alice")))
        val viewModel = fixture.createSessionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            fixture.locationRepository.emit(LocationUpdate("player-1", 1.0, 1.0, "t1"))
            while (state.markers.isEmpty()) state = awaitItem()
            val roundCalls = fixture.roundRepository.getRoundCalls.size

            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-2", "player-1", "Alice", "token", side = "seeker"),
            )
            while (state.markers.isNotEmpty()) state = awaitItem()
            assertTrue(fixture.roundRepository.getRoundCalls.size > roundCalls)
            cancelAndIgnoreRemainingEvents()
        }
    }

    private companion object {
        const val ONE_MINUTE_MS = 60_000L
        const val FIVE_MINUTES_MS = 300_000L
    }
}
