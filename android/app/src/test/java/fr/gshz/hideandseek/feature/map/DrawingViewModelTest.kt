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
class DrawingViewModelTest {

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
    fun `manual constraints are loaded on entry for all roles`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        fixture.manualConstraintRepository.constraints = mutableListOf(
            ManualConstraint("c-1", ConstraintMode.Include, "geo-c1", "Downtown", "Bob"),
        )
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.manualConstraints.isEmpty()) state = awaitItem()
            assertEquals("c-1", state.manualConstraints.single().uuid)
            assertEquals(ConstraintMode.Include, state.manualConstraints.single().mode)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `an SSE manual-constraint-added event appends it and refreshes the possible area`() =
        runTest(testDispatcher) {
            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
            )
            fixture.possibleAreaRepository.getPossibleAreaResult = PossibleAreaData("geo-1", null)
            val viewModel = fixture.createDrawingViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                fixture.gameEventRepository.emitManualConstraintAdded(
                    ManualConstraintAddedEvent("c-9", ConstraintMode.Exclude, "geo-c9"),
                )

                while (state.manualConstraints.none { it.uuid == "c-9" }) state = awaitItem()
                assertEquals(ConstraintMode.Exclude, state.manualConstraints.single { it.uuid == "c-9" }.mode)
                assertTrue(fixture.possibleAreaRefreshFlow.refresh.value >= 1)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `an SSE manual-constraint-removed event drops it and refreshes the possible area`() =
        runTest(testDispatcher) {
            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
            )
            fixture.manualConstraintRepository.constraints = mutableListOf(
                ManualConstraint("c-1", ConstraintMode.Include, "geo-c1", "Downtown", null),
            )
            val viewModel = fixture.createDrawingViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                while (state.manualConstraints.isEmpty()) state = awaitItem()

                fixture.gameEventRepository.emitManualConstraintRemoved(ManualConstraintRemovedEvent("c-1"))
                while (state.manualConstraints.any { it.uuid == "c-1" }) state = awaitItem()
                assertTrue(fixture.possibleAreaRefreshFlow.refresh.value >= 1)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `a seeker can add a manual constraint through the repository`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.addManualConstraint("geo-new", ConstraintMode.Include, "Center")
            while (state.manualConstraints.isEmpty()) state = awaitItem()

            val call = fixture.manualConstraintRepository.addCalls.single()
            assertEquals("round-1", call.roundUuid)
            assertEquals("player-1", call.playerUuid)
            assertEquals(ConstraintMode.Include, call.mode)
            assertEquals("geo-new", call.geoJson)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a seeker can delete a manual constraint through the repository`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        fixture.manualConstraintRepository.constraints = mutableListOf(
            ManualConstraint("c-1", ConstraintMode.Include, "geo-c1", "Downtown", null),
        )
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.manualConstraints.isEmpty()) state = awaitItem()

            viewModel.deleteManualConstraint("c-1")
            while (state.manualConstraints.isNotEmpty()) state = awaitItem()

            assertEquals(
                Triple("round-1", "c-1", "player-1"),
                fixture.manualConstraintRepository.deleteCalls.single(),
            )
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a hider cannot mutate manual constraints`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.addManualConstraint("geo-new", ConstraintMode.Include, "Center")
            advanceTimeBy(1_000)

            assertTrue(fixture.manualConstraintRepository.addCalls.isEmpty())
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a hider entering drawing mode is a no-op`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.enterDrawing()
            advanceTimeBy(1_000)

            assertFalse(state.drawing.isActive)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a seeker enters drawing, adds points, and closing commits a closed-ring polygon then exits`() =
        runTest(testDispatcher) {
            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
            )
            val viewModel = fixture.createDrawingViewModel()

            viewModel.uiState.test {
                var state = awaitItem()

                viewModel.enterDrawing()
                while (!state.drawing.isActive) state = awaitItem()

                viewModel.updateDrawing { it.copy(vertices = it.vertices + ZonePin(0.0, 0.0)) }
                viewModel.updateDrawing { it.copy(vertices = it.vertices + ZonePin(1.0, 2.0)) }
                viewModel.updateDrawing { it.copy(vertices = it.vertices + ZonePin(3.0, 4.0)) }
                while (state.drawing.vertices.size != 3) state = awaitItem()
                assertTrue(state.drawing.canClose)

                viewModel.confirmDrawing()
                while (state.drawing.isActive) state = awaitItem()

                val call = fixture.manualConstraintRepository.addCalls.single()
                assertEquals(ConstraintMode.Exclude, call.mode)
                assertEquals(
                    """{"type":"Polygon","coordinates":[[[0.0,0.0],[2.0,1.0],[4.0,3.0],[0.0,0.0]]]}""",
                    call.geoJson,
                )
                assertFalse(state.drawing.isActive)
                assertTrue(state.drawing.vertices.isEmpty())
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `an inverted drawing commits with the include mode`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.enterDrawing()
            while (!state.drawing.isActive) state = awaitItem()
            viewModel.updateDrawing { it.copy(mode = ConstraintMode.Include) }
            while (state.drawing.mode != ConstraintMode.Include) state = awaitItem()

            viewModel.updateDrawing { it.copy(vertices = it.vertices + ZonePin(0.0, 0.0)) }
            viewModel.updateDrawing { it.copy(vertices = it.vertices + ZonePin(0.0, 1.0)) }
            viewModel.updateDrawing { it.copy(vertices = it.vertices + ZonePin(1.0, 1.0)) }
            while (state.drawing.vertices.size != 3) state = awaitItem()

            viewModel.confirmDrawing()
            while (state.drawing.isActive) state = awaitItem()

            assertEquals(ConstraintMode.Include, fixture.manualConstraintRepository.addCalls.single().mode)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `moving a vertex repositions only that point`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.enterDrawing()
            while (!state.drawing.isActive) state = awaitItem()
            viewModel.updateDrawing { it.copy(vertices = it.vertices + ZonePin(0.0, 0.0)) }
            viewModel.updateDrawing { it.copy(vertices = it.vertices + ZonePin(1.0, 1.0)) }
            while (state.drawing.vertices.size != 2) state = awaitItem()

            viewModel.updateDrawing { state ->
                state.copy(
                    vertices = state.vertices.mapIndexed { i, v -> if (i == 0) ZonePin(5.0, 6.0) else v },
                )
            }
            while (state.drawing.vertices.first() != ZonePin(5.0, 6.0)) state = awaitItem()

            assertEquals(ZonePin(1.0, 1.0), state.drawing.vertices[1])
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `removeVertex drops the tapped vertex`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.enterDrawing()
            while (!state.drawing.isActive) state = awaitItem()
            viewModel.updateDrawing {
                it.copy(vertices = listOf(ZonePin(0.0, 0.0), ZonePin(1.0, 1.0), ZonePin(2.0, 2.0)))
            }
            while (state.drawing.vertices.size != 3) state = awaitItem()

            viewModel.updateDrawing {
                it.copy(
                    vertices = it.vertices.filterIndexed { i, _ -> i != 1 },
                    draggingVertexIndex = null,
                )
            }
            while (state.drawing.vertices.size != 2) state = awaitItem()

            assertEquals(listOf(ZonePin(0.0, 0.0), ZonePin(2.0, 2.0)), state.drawing.vertices)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `insertVertex adds a point between two others`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.enterDrawing()
            while (!state.drawing.isActive) state = awaitItem()
            viewModel.updateDrawing { it.copy(vertices = listOf(ZonePin(0.0, 0.0), ZonePin(2.0, 2.0))) }
            while (state.drawing.vertices.size != 2) state = awaitItem()

            viewModel.updateDrawing {
                it.copy(
                    vertices = it.vertices.toMutableList().apply { add(1, ZonePin(1.0, 1.0)) },
                )
            }
            while (state.drawing.vertices.size != 3) state = awaitItem()

            assertEquals(
                listOf(ZonePin(0.0, 0.0), ZonePin(1.0, 1.0), ZonePin(2.0, 2.0)),
                state.drawing.vertices,
            )
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `closing with fewer than three vertices is a no-op`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.enterDrawing()
            while (!state.drawing.isActive) state = awaitItem()
            viewModel.updateDrawing { it.copy(vertices = listOf(ZonePin(0.0, 0.0), ZonePin(1.0, 1.0))) }
            while (state.drawing.vertices.size != 2) state = awaitItem()
            assertFalse(state.drawing.canClose)

            viewModel.confirmDrawing()
            advanceTimeBy(1_000)

            assertTrue(fixture.manualConstraintRepository.addCalls.isEmpty())
            assertTrue(state.drawing.isActive)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `cancelling discards the in-progress drawing`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.enterDrawing()
            while (!state.drawing.isActive) state = awaitItem()
            viewModel.updateDrawing { it.copy(vertices = listOf(ZonePin(0.0, 0.0), ZonePin(1.0, 1.0))) }
            while (state.drawing.vertices.size != 2) state = awaitItem()

            viewModel.cancelDrawing()
            while (state.drawing.isActive) state = awaitItem()

            assertTrue(state.drawing.vertices.isEmpty())
            assertTrue(fixture.manualConstraintRepository.addCalls.isEmpty())
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `selecting a manual constraint then deleting it calls the repository and clears the selection`() =
        runTest(testDispatcher) {
            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
            )
            fixture.manualConstraintRepository.constraints = mutableListOf(
                ManualConstraint("c-1", ConstraintMode.Include, "geo-c1", "Downtown", null),
            )
            val viewModel = fixture.createDrawingViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                while (state.manualConstraints.isEmpty()) state = awaitItem()

                viewModel.updateDrawing { it.copy(selectedManualConstraintUuid = "c-1") }
                while (state.selectedManualConstraintUuid != "c-1") state = awaitItem()

                viewModel.deleteSelectedManualConstraint()
                while (state.selectedManualConstraintUuid != null) state = awaitItem()

                assertEquals(
                    Triple("round-1", "c-1", "player-1"),
                    fixture.manualConstraintRepository.deleteCalls.single(),
                )
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `the echo of a constraint this device drew does not refetch the possible area again`() =
        runTest(testDispatcher) {
            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
            )
            val viewModel = fixture.createDrawingViewModel()

            viewModel.uiState.test {
                var state = awaitItem()

                viewModel.addManualConstraint("geo-1", ConstraintMode.Exclude)
                while (state.manualConstraints.isEmpty()) state = awaitItem()
                val added = state.manualConstraints.single()
                val refreshesAfterAdd = fixture.possibleAreaRefreshFlow.refresh.value

                fixture.gameEventRepository.emitManualConstraintAdded(
                    ManualConstraintAddedEvent(added.uuid, ConstraintMode.Exclude, "geo-1"),
                )
                fixture.gameEventRepository.emitManualConstraintAdded(
                    ManualConstraintAddedEvent("constraint-2", ConstraintMode.Include, "geo-2"),
                )
                while (state.manualConstraints.size < 2) state = awaitItem()

                assertEquals(refreshesAfterAdd + 1, fixture.possibleAreaRefreshFlow.refresh.value)
                cancelAndIgnoreRemainingEvents()
            }
        }

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

