package fr.gshz.hideandseek.feature.map

import app.cash.turbine.test
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.domain.model.SeekerMarker
import fr.gshz.hideandseek.domain.repository.SeekerCandidateAdded
import fr.gshz.hideandseek.domain.repository.SeekerCandidateRemoved
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class SeekerMarkersViewModelTest {

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
    fun `a seeker loads existing suspected-station markers on entry`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        fixture.seekerMarkerRepository.markers = mutableListOf(SeekerMarker("m-1", "player-2", 1.0, 2.0))
        val viewModel = fixture.createSeekerMarkersViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.isEmpty()) state = awaitItem()
            assertEquals("m-1", state.single().uuid)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `marking a suspected station adds it to the seeker markers`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        val viewModel = fixture.createSeekerMarkersViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.markSuspectedStation(48.85, 2.35)
            while (state.isEmpty()) state = awaitItem()

            assertEquals(48.85, state.single().lat, 0.0)
            val call = fixture.seekerMarkerRepository.addCalls.single()
            assertEquals("round-1", call.roundUuid)
            assertEquals("player-1", call.playerUuid)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `unmarking a station removes it from the seeker markers`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        fixture.seekerMarkerRepository.markers = mutableListOf(SeekerMarker("m-1", "player-1", 1.0, 2.0))
        val viewModel = fixture.createSeekerMarkersViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.isEmpty()) state = awaitItem()

            viewModel.unmarkStation("m-1")
            while (state.isNotEmpty()) state = awaitItem()

            assertEquals(listOf("round-1" to "m-1"), fixture.seekerMarkerRepository.deleteCalls)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `an SSE candidate-added event adds a marker and candidate-removed drops it`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        val viewModel = fixture.createSeekerMarkersViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            fixture.gameEventRepository.emitSeekerCandidateAdded(SeekerCandidateAdded("m-9", "player-2", 5.0, 6.0))
            while (state.none { it.uuid == "m-9" }) state = awaitItem()

            fixture.gameEventRepository.emitSeekerCandidateRemoved(SeekerCandidateRemoved("m-9"))
            while (state.any { it.uuid == "m-9" }) state = awaitItem()
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a hider never loads suspected-station markers and marking is inert`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        fixture.seekerMarkerRepository.markers = mutableListOf(SeekerMarker("m-1", "player-2", 1.0, 2.0))
        val viewModel = fixture.createSeekerMarkersViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.markSuspectedStation(48.85, 2.35)
            advanceTimeBy(1_000)

            assertTrue(state.isEmpty())
            assertTrue(fixture.seekerMarkerRepository.addCalls.isEmpty())
            cancelAndIgnoreRemainingEvents()
        }
    }
}
