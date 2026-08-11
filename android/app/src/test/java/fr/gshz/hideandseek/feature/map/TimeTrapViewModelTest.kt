package fr.gshz.hideandseek.feature.map

import androidx.lifecycle.SavedStateHandle
import app.cash.turbine.test
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.domain.model.TimeTrapStatus
import fr.gshz.hideandseek.domain.repository.TimeTrapEvent
import fr.gshz.hideandseek.fake.FakeTimeTrapRepository
import java.io.IOException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class TimeTrapViewModelTest {

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
    fun `traps are seeded over REST because the event flows have no replay`() = runTest(testDispatcher) {
        seedHider()
        fixture.timeTrapRepository.listResult = Result.success(listOf(FakeTimeTrapRepository.trap("trap-1")))
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.timeTraps.isEmpty()) state = awaitItem()

            assertEquals("trap-1", state.timeTraps.single().uuid)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `entering placement mode gates the pin and hides nothing else`() = runTest(testDispatcher) {
        seedHider()
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.enterTimeTrapPlacement()
            while (!state.isPlacingTimeTrap) state = awaitItem()
            assertNull(state.pendingTrapPin)

            viewModel.placeTimeTrapPin(46.52, 6.63, "Flon")
            while (state.pendingTrapPin == null) state = awaitItem()
            assertEquals("Flon", state.pendingTrapStationName)

            viewModel.cancelTimeTrapPlacement()
            while (state.isPlacingTimeTrap) state = awaitItem()
            assertNull(state.pendingTrapPin)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a seeker cannot enter trap placement`() = runTest(testDispatcher) {
        seedSeeker()
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.enterTimeTrapPlacement()
            advanceTimeBy(TICK_MS)

            assertFalse(viewModel.uiState.value.isPlacingTimeTrap)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a pin without a placement mode open is ignored`() = runTest(testDispatcher) {
        seedHider()
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.placeTimeTrapPin(46.52, 6.63, "Flon")
            advanceTimeBy(TICK_MS)

            assertNull(viewModel.uiState.value.pendingTrapPin)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a pin inside the hiders' own zone still lands`() = runTest(testDispatcher) {
        seedHider()
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.enterTimeTrapPlacement()
            viewModel.placeTimeTrapPin(46.5205, 6.6305, "Flon")
            while (state.pendingTrapPin == null) state = awaitItem()
            assertEquals("Flon", state.pendingTrapStationName)

            viewModel.placeTimeTrapPin(46.60, 6.80, "Renens")
            while (state.pendingTrapPin?.latitude != 46.60) state = awaitItem()
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `confirming posts the pin with the card photo and closes placement`() = runTest(testDispatcher) {
        seedHider()
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.enterTimeTrapPlacement()
            viewModel.placeTimeTrapPin(46.52, 6.63, "Flon")
            while (state.pendingTrapPin == null) state = awaitItem()

            viewModel.confirmTimeTrap("content://card.jpg")
            while (fixture.timeTrapRepository.placeCalls.isEmpty()) state = awaitItem()

            val call = fixture.timeTrapRepository.placeCalls.single()
            assertEquals("round-1", call.roundUuid)
            assertEquals("player-1", call.playerUuid)
            assertEquals(46.52, call.lat, 0.0)
            assertEquals(6.63, call.lng, 0.0)
            assertEquals("content://card.jpg", call.cardPhotoUri)

            while (state.isPlacingTimeTrap) state = awaitItem()
            assertEquals(listOf("trap-placed"), state.timeTraps.map { it.uuid })
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `the live value steps at interval boundaries off the ViewModel ticker`() = runTest(testDispatcher) {
        seedHider()
        val placedAt = System.currentTimeMillis() - FIFTEEN_MINUTES_MS - ONE_SECOND_MS
        fixture.timeTrapRepository.listResult = Result.success(
            listOf(FakeTimeTrapRepository.trap("trap-1", placedAtEpochMs = placedAt)),
        )
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.timeTraps.isEmpty()) state = awaitItem()

            assertEquals(FOUR_MINUTES_SECONDS, state.timeTraps.single().valueSeconds)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a trap is worth nothing for its whole first interval`() = runTest(testDispatcher) {
        seedHider()
        fixture.timeTrapRepository.listResult = Result.success(
            listOf(FakeTimeTrapRepository.trap("trap-1", placedAtEpochMs = System.currentTimeMillis())),
        )
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.timeTraps.isEmpty()) state = awaitItem()

            assertEquals(0, state.timeTraps.single().valueSeconds)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a placed event adds a trap every player can see`() = runTest(testDispatcher) {
        seedSeeker()
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            fixture.gameEventRepository.emitTimeTrapEvent(
                TimeTrapEvent("placed", FakeTimeTrapRepository.trap("trap-9")),
            )
            while (state.timeTraps.isEmpty()) state = awaitItem()

            assertEquals("trap-9", state.timeTraps.single().uuid)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `only a seeker is asked to rule on a detection`() = runTest(testDispatcher) {
        seedHider()
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            fixture.gameEventRepository.emitTimeTrapEvent(detected())
            while (state.timeTraps.isEmpty()) state = awaitItem()

            assertNull(state.pendingTrapDetection)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a seeker gets the detection prompt and resolving it clears the prompt`() = runTest(testDispatcher) {
        seedSeeker()
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            fixture.gameEventRepository.emitTimeTrapEvent(detected())
            while (state.pendingTrapDetection == null) state = awaitItem()

            viewModel.resolveTimeTrap("trap-1", confirmed = true)
            while (state.pendingTrapDetection != null) state = awaitItem()

            val call = fixture.timeTrapRepository.resolveCalls.single()
            assertEquals("trap-1", call.trapUuid)
            assertEquals("player-1", call.playerUuid)
            assertTrue(call.confirmed)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a sprung trap leaves the map because it is spent`() = runTest(testDispatcher) {
        seedSeeker()
        fixture.timeTrapRepository.listResult = Result.success(listOf(FakeTimeTrapRepository.trap("trap-1")))
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.timeTraps.isEmpty()) state = awaitItem()

            fixture.gameEventRepository.emitTimeTrapEvent(
                TimeTrapEvent("sprung", FakeTimeTrapRepository.trap("trap-1", TimeTrapStatus.Sprung)),
            )
            while (state.timeTraps.isNotEmpty()) state = awaitItem()
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a dismissal re-arms the trap and closes the prompt`() = runTest(testDispatcher) {
        seedSeeker()
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            fixture.gameEventRepository.emitTimeTrapEvent(detected())
            while (state.pendingTrapDetection == null) state = awaitItem()

            fixture.gameEventRepository.emitTimeTrapEvent(
                TimeTrapEvent("dismissed", FakeTimeTrapRepository.trap("trap-1", TimeTrapStatus.Armed)),
            )
            while (state.pendingTrapDetection != null) state = awaitItem()

            assertEquals(TimeTrapStatus.Armed, state.timeTraps.single().status)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a pending trap seeded over REST prompts a seeker who restarted`() = runTest(testDispatcher) {
        seedSeeker()
        fixture.timeTrapRepository.listResult = Result.success(
            listOf(FakeTimeTrapRepository.trap("trap-1", TimeTrapStatus.Pending)),
        )
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.pendingTrapDetection == null) state = awaitItem()

            assertEquals("trap-1", state.pendingTrapDetection?.uuid)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a second detection never buries the first`() = runTest(testDispatcher) {
        seedSeeker()
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            fixture.gameEventRepository.emitTimeTrapEvent(detected("trap-a"))
            fixture.gameEventRepository.emitTimeTrapEvent(detected("trap-b"))
            while (state.timeTraps.size < 2) state = awaitItem()

            assertEquals("trap-a", state.pendingTrapDetection?.uuid)

            viewModel.resolveTimeTrap("trap-a", confirmed = true)
            while (state.pendingTrapDetection?.uuid != "trap-b") state = awaitItem()
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a refused resolve keeps the prompt up and surfaces the error`() = runTest(testDispatcher) {
        seedSeeker()
        fixture.timeTrapRepository.resolveResult = Result.failure(IOException("no signal"))
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            fixture.gameEventRepository.emitTimeTrapEvent(detected("trap-1"))
            while (state.pendingTrapDetection == null) state = awaitItem()

            viewModel.resolveTimeTrap("trap-1", confirmed = false)
            while (state.timeTrapError == null) state = awaitItem()

            assertEquals(ErrorType.Network, state.timeTrapError)
            assertEquals("trap-1", state.pendingTrapDetection?.uuid)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a trap published for another round is ignored`() = runTest(testDispatcher) {
        seedSeeker()
        val viewModel = fixture.createTimeTrapViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            fixture.gameEventRepository.emitTimeTrapEvent(
                TimeTrapEvent("placed", FakeTimeTrapRepository.trap("trap-x").copy(roundUuid = "round-2")),
            )
            advanceTimeBy(TICK_MS)
            assertTrue(viewModel.uiState.value.timeTraps.isEmpty())

            fixture.gameEventRepository.emitTimeTrapEvent(
                TimeTrapEvent("placed", FakeTimeTrapRepository.trap("trap-y")),
            )
            while (state.timeTraps.isEmpty()) state = awaitItem()

            assertEquals(listOf("trap-y"), state.timeTraps.map { it.uuid })
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `an open placement survives the process death the camera can cause`() = runTest(testDispatcher) {
        seedHider()
        val handle = SavedStateHandle(mapOf(HideAndSeekDestinations.MAP_ARG to "game-1"))
        val doomed = fixture.createTimeTrapViewModel(handle)

        doomed.uiState.test {
            var state = awaitItem()
            doomed.enterTimeTrapPlacement()
            doomed.placeTimeTrapPin(46.52, 6.63, "Flon")
            while (state.pendingTrapPin == null) state = awaitItem()
            cancelAndIgnoreRemainingEvents()
        }

        fixture.createTimeTrapViewModel(handle).uiState.test {
            var state = awaitItem()
            while (state.pendingTrapPin == null) state = awaitItem()

            assertTrue(state.isPlacingTimeTrap)
            assertEquals(46.52, state.pendingTrapPin?.latitude)
            assertEquals("Flon", state.pendingTrapStationName)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `cancelling clears the persisted draft so the next launch starts clean`() = runTest(testDispatcher) {
        seedHider()
        val handle = SavedStateHandle(mapOf(HideAndSeekDestinations.MAP_ARG to "game-1"))
        val first = fixture.createTimeTrapViewModel(handle)

        first.uiState.test {
            var state = awaitItem()
            first.enterTimeTrapPlacement()
            first.placeTimeTrapPin(46.52, 6.63, "Flon")
            while (state.pendingTrapPin == null) state = awaitItem()
            first.cancelTimeTrapPlacement()
            while (state.isPlacingTimeTrap) state = awaitItem()
            cancelAndIgnoreRemainingEvents()
        }

        val restored = fixture.createTimeTrapViewModel(handle)
        restored.uiState.test {
            var state = awaitItem()

            assertFalse(restored.uiState.value.isPlacingTimeTrap)
            assertNull(restored.uiState.value.pendingTrapPin)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a round rekey clears the persisted draft so a process death cannot re-open the old round's pin`() =
        runTest(testDispatcher) {
            seedHider()
            val handle = SavedStateHandle(mapOf(HideAndSeekDestinations.MAP_ARG to "game-1"))
            val first = fixture.createTimeTrapViewModel(handle)

            first.uiState.test {
                var state = awaitItem()
                first.enterTimeTrapPlacement()
                first.placeTimeTrapPin(46.52, 6.63, "Flon")
                while (state.pendingTrapPin == null) state = awaitItem()
                cancelAndIgnoreRemainingEvents()
            }

            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-2", "player-1", "Alice", "token", side = "hider"),
            )

            val restored = fixture.createTimeTrapViewModel(handle)
            restored.uiState.test {
                var state = awaitItem()

                assertFalse(restored.uiState.value.isPlacingTimeTrap)
                assertNull(restored.uiState.value.pendingTrapPin)
                cancelAndIgnoreRemainingEvents()
            }
        }

    private fun detected(uuid: String = "trap-1") = TimeTrapEvent(
        "detected",
        FakeTimeTrapRepository.trap(uuid, TimeTrapStatus.Pending).copy(valueSeconds = FOUR_MINUTES_SECONDS),
    )

    private fun seedHider() {
        fixture.sessionRepository.seed(PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"))
    }

    private fun seedSeeker() {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
    }

    private companion object {
        const val ONE_SECOND_MS = 1_000L
        const val TICK_MS = 1_000L
        const val FIFTEEN_MINUTES_MS = 900_000L
        const val FOUR_MINUTES_SECONDS = 240
    }
}
