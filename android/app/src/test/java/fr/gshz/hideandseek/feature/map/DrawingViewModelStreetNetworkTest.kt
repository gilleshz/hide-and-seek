package fr.gshz.hideandseek.feature.map

import app.cash.turbine.test
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.navigation.TraceRequest
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.ConstraintMode
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.ManualConstraint
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.StreetPoint
import fr.gshz.hideandseek.domain.model.StreetWay
import fr.gshz.hideandseek.domain.repository.ManualConstraintAddedEvent
import fr.gshz.hideandseek.domain.repository.ManualConstraintRemovedEvent
import fr.gshz.hideandseek.domain.repository.PossibleAreaData
import fr.gshz.hideandseek.fake.FakeStreetNetworkRepository
import io.mockk.coEvery
import io.mockk.coVerify
import java.io.IOException
import kotlin.coroutines.CoroutineContext
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.CoroutineDispatcher
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
import org.junit.jupiter.api.Assertions.assertNotNull
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class DrawingViewModelStreetNetworkTest {

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
    fun `a new round clears the selection instead of keeping the last round's streets`() =
        runTest(testDispatcher) {
            seedHiderWithNetwork()
            val viewModel = fixture.createDrawingViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                while (state.drawing.streetStatus != StreetDataStatus.Available || !state.drawing.isActive) {
                    state = awaitItem()
                }
                selectLongEdge(viewModel)
                while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()

                fixture.streetNetworkRepository.networkResult = Result.success(
                    FakeStreetNetworkRepository.ready(
                        FakeStreetNetworkRepository.way(points = OTHER_CITY_POINTS),
                    ),
                )
                fixture.sessionRepository.seed(
                    PlayerSession("game-1", "round-2", "player-1", "Alice", "token", side = "hider"),
                )
                while (state.drawing.isActive) state = awaitItem()

                fixture.navigationRequestStore.requestTrace(
                    TraceRequest("game-1", "q-2", PhotoTarget.TraceNearestStreet),
                )
                while (!state.drawing.isActive) state = awaitItem()

                assertTrue(state.drawing.selectedPaths.isEmpty())
                assertEquals(
                    listOf("round-1", "round-2"),
                    fixture.streetNetworkRepository.fetchCalls.map { it.roundUuid },
                )
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `a seeker never fetches the hiders' street network`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        fixture.createDrawingViewModel()

        assertTrue(fixture.streetNetworkRepository.fetchCalls.isEmpty())
    }

    @Test
    fun `a failed street-network fetch leaves the trace with no streets to tap`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        fixture.streetNetworkRepository.networkResult = Result.failure(IOException("offline"))
        fixture.navigationRequestStore.requestTrace(TraceRequest("game-1", "q-1", PhotoTarget.TraceNearestStreet))
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (!state.drawing.isActive) state = awaitItem()
            while (state.drawing.streetStatus == StreetDataStatus.Loading) state = awaitItem()

            assertEquals(StreetDataStatus.Unavailable, state.drawing.streetStatus)
            assertTrue(state.drawing.streetDataUnavailable)

            selectLongEdge(viewModel)
            assertTrue(state.drawing.selectedPaths.isEmpty())
            assertFalse(state.drawing.canConfirmTrace)
            assertEquals(2, fixture.streetNetworkRepository.fetchCalls.size)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `an unavailable body still leaves nothing to tap`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        fixture.streetNetworkRepository.networkResult =
            Result.success(FakeStreetNetworkRepository.unavailable(streetWay()))
        fixture.navigationRequestStore.requestTrace(TraceRequest("game-1", "q-1", PhotoTarget.TraceNearestStreet))
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (!state.drawing.isActive) state = awaitItem()
            while (state.drawing.streetStatus == StreetDataStatus.Loading) state = awaitItem()

            assertEquals(StreetDataStatus.Unavailable, state.drawing.streetStatus)
            selectLongEdge(viewModel)
            assertTrue(state.drawing.selectedPaths.isEmpty())
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a pending body keeps loading and offers nothing to tap yet`() = runTest(testDispatcher) {
        seedHiderPending(streetWay())
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (!state.drawing.isActive) state = awaitItem()

            assertEquals(StreetDataStatus.Loading, state.drawing.streetStatus)
            selectLongEdge(viewModel)
            assertTrue(state.drawing.selectedPaths.isEmpty())
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a network that lands mid-draw becomes tappable`() = runTest(testDispatcher) {
        seedHiderWithNetwork()
        val landing = CompletableDeferred<Unit>()
        fixture.streetNetworkRepository.fetchGate = landing
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (!state.drawing.isActive) state = awaitItem()
            assertEquals(StreetDataStatus.Loading, state.drawing.streetStatus)
            selectLongEdge(viewModel)
            assertTrue(state.drawing.selectedPaths.isEmpty())

            landing.complete(Unit)
            while (state.drawing.streetStatus != StreetDataStatus.Available) state = awaitItem()

            selectLongEdge(viewModel)
            while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()
            assertEquals(1, state.drawing.selectedPaths.size)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a pending network is polled until the server finishes warming it`() = runTest(testDispatcher) {
        seedHiderPending()
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (!state.drawing.isActive) state = awaitItem()
            assertEquals(StreetDataStatus.Loading, state.drawing.streetStatus)
            assertEquals(1, fixture.streetNetworkRepository.fetchCalls.size)

            fixture.streetNetworkRepository.networkResult =
                Result.success(FakeStreetNetworkRepository.ready(streetWay()))
            advanceTimeBy(POLL_STEP_MS)
            while (state.drawing.streetStatus != StreetDataStatus.Available) state = awaitItem()

            assertEquals(2, fixture.streetNetworkRepository.fetchCalls.size)
            selectLongEdge(viewModel)
            while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()
            assertEquals(1, state.drawing.selectedPaths.size)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a network still pending after the warm window gives up rather than polling forever`() =
        runTest(testDispatcher) {
            seedHiderPending()
            val viewModel = fixture.createDrawingViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                while (!state.drawing.isActive) state = awaitItem()

                advanceTimeBy(WARM_ATTEMPTS * POLL_STEP_MS)
                while (state.drawing.streetStatus == StreetDataStatus.Loading) state = awaitItem()

                assertEquals(StreetDataStatus.Unavailable, state.drawing.streetStatus)
                assertEquals(WARM_ATTEMPTS, fixture.streetNetworkRepository.fetchCalls.size)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `a fetch in flight reads as loading rather than as a network that will never come`() =
        runTest(testDispatcher) {
            seedHiderWithNetwork()
            val landing = CompletableDeferred<Unit>()
            fixture.streetNetworkRepository.fetchGate = landing
            val viewModel = fixture.createDrawingViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                while (!state.drawing.isActive) state = awaitItem()

                assertEquals(StreetDataStatus.Loading, state.drawing.streetStatus)

                landing.complete(Unit)
                while (state.drawing.streetStatus != StreetDataStatus.Available) state = awaitItem()

                assertEquals(StreetDataStatus.Available, state.drawing.streetStatus)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `the payload is parsed and the graph is built off the main thread`() = runTest(testDispatcher) {
        seedHiderWithNetwork()
        val offMain = CountingDispatcher()
        val loader = StreetNetworkLoader(fixture.streetNetworkRepository, fixture.navigationRequestStore, offMain)
        val viewModel = DrawingViewModel(
            loader, fixture.manualConstraintSource, fixture.questionRepository, fixture.traceImageWriter,
            fixture.sessionEvents, fixture.zonePlacementCancelSignal,
        )

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.drawing.streetStatus != StreetDataStatus.Available) state = awaitItem()

            assertTrue(offMain.dispatches > 0)
            cancelAndIgnoreRemainingEvents()
        }
    }

    private class CountingDispatcher : CoroutineDispatcher() {
        var dispatches = 0

        override fun dispatch(context: CoroutineContext, block: Runnable) {
            dispatches++
            block.run()
        }
    }

    private fun seedHiderPending(vararg ways: StreetWay) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        fixture.streetNetworkRepository.networkResult = Result.success(FakeStreetNetworkRepository.pending(*ways))
        fixture.navigationRequestStore.requestTrace(TraceRequest("game-1", "q-1", PhotoTarget.TraceNearestStreet))
    }

    private fun seedHiderWithNetwork(photoTarget: PhotoTarget = PhotoTarget.TraceNearestStreet) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        fixture.streetNetworkRepository.networkResult = Result.success(FakeStreetNetworkRepository.ready(streetWay()))
        fixture.navigationRequestStore.requestTrace(TraceRequest("game-1", "q-1", photoTarget))
    }

    private fun streetWay() =
        FakeStreetNetworkRepository.way(points = STREET_POINTS, junctionIndices = STREET_JUNCTIONS)

    private fun selectLongEdge(viewModel: DrawingViewModel) =
        viewModel.toggleEdgeAt(LONG_EDGE_TAP.latitude, LONG_EDGE_TAP.longitude)

    private companion object {
        const val TRACE_IMAGE_URI = "content://fr.gshz.hideandseek.fileprovider/traces/trace.png"

        const val TRACE_MINIMUM_METERS = 1000.0
        const val HALF_MILE_METERS = 804.672

        // One junction splits the way into a ~111 m short edge and a ~2.1 km long edge, straddling both minimums.
        val STREET_POINTS = listOf(
            StreetPoint(0.0, 0.0),
            StreetPoint(0.001, 0.0),
            StreetPoint(0.02, 0.0),
        )
        val STREET_JUNCTIONS = listOf(1)
        val SHORT_EDGE_TAP = ZonePin(0.0005, 0.00001)
        val LONG_EDGE_TAP = ZonePin(0.0105, 0.00001)

        val OTHER_CITY_POINTS = listOf(StreetPoint(1.0, 1.0), StreetPoint(1.0, 1.02))

        const val WARM_ATTEMPTS = 3
        const val POLL_STEP_MS = 11_000L
    }

}
