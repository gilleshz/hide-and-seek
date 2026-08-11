package fr.gshz.hideandseek.feature.map

import app.cash.turbine.test
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.navigation.TraceRequest
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.StreetPoint
import fr.gshz.hideandseek.fake.FakeStreetNetworkRepository
import io.mockk.coEvery
import io.mockk.coVerify
import java.io.IOException
import kotlinx.coroutines.CompletableDeferred
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
class DrawingViewModelTraceTest {

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
    fun `a pending trace request enters trace mode and is consumed`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        fixture.navigationRequestStore.requestTrace(TraceRequest("game-1", "q-1", PhotoTarget.StreetsTraced))

        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (!state.drawing.isActive) state = awaitItem()

            assertEquals(DrawKind.Trace, state.drawing.kind)
            assertEquals("q-1", state.drawing.questionUuid)
            assertEquals(PhotoTarget.StreetsTraced, state.drawing.photoTarget)
            assertNull(fixture.navigationRequestStore.pendingTraceRequest.value)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a streets-traced trace only confirms once it reaches the minimum length`() = runTest(testDispatcher) {
        seedHiderWithNetwork(PhotoTarget.StreetsTraced)
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.drawing.streetStatus != StreetDataStatus.Available || !state.drawing.isActive) {
                state = awaitItem()
            }
            selectShortEdge(viewModel)
            while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()
            assertEquals(TRACE_MINIMUM_METERS, state.drawing.minimumMeters ?: 0.0, 0.0)
            assertTrue(state.drawing.lengthMeters < TRACE_MINIMUM_METERS)
            assertFalse(state.drawing.canConfirmTrace)

            selectLongEdge(viewModel)
            while (state.drawing.selectedPaths.size != 2) state = awaitItem()
            assertTrue(state.drawing.lengthMeters >= TRACE_MINIMUM_METERS)
            assertTrue(state.drawing.canConfirmTrace)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a nearest-street trace has no minimum so one street is enough`() = runTest(testDispatcher) {
        seedHiderWithNetwork()
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.drawing.streetStatus != StreetDataStatus.Available || !state.drawing.isActive) {
                state = awaitItem()
            }
            selectShortEdge(viewModel)
            while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()

            assertNull(state.drawing.minimumMeters)
            assertTrue(state.drawing.canConfirmTrace)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `confirming a trace shows the preview without submitting it`() = runTest(testDispatcher) {
        seedHiderWithNetwork()
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.drawing.streetStatus != StreetDataStatus.Available || !state.drawing.isActive) {
                state = awaitItem()
            }
            selectLongEdge(viewModel)
            while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()

            viewModel.confirmTrace(Edition.Metric)
            while (state.traceReview == null) state = awaitItem()

            assertEquals(TRACE_IMAGE_URI, state.traceReview?.imageUri)
            assertEquals(1, fixture.traceImageWriter.writeCount)
            assertEquals(state.drawing.selectedPaths, fixture.traceImageWriter.receivedPolylines)
            coVerify(exactly = 0) { fixture.questionRepository.revealPhotoQuestion(any(), any(), any()) }
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `sending a trace submits the generated image and clears the trace`() = runTest(testDispatcher) {
        seedHiderWithNetwork()
        coEvery { fixture.questionRepository.revealPhotoQuestion(any(), any(), any()) } returns
            askedQuestion(PhotoTarget.StreetsTraced)
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.drawing.streetStatus != StreetDataStatus.Available || !state.drawing.isActive) {
                state = awaitItem()
            }
            selectLongEdge(viewModel)
            while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()
            viewModel.confirmTrace(Edition.Metric)
            while (state.traceReview == null) state = awaitItem()

            viewModel.sendTrace()
            while (state.traceReview != null) state = awaitItem()

            coVerify { fixture.questionRepository.revealPhotoQuestion("q-1", "player-1", TRACE_IMAGE_URI) }
            assertFalse(state.drawing.isActive)
            assertTrue(state.drawing.selectedPaths.isEmpty())
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `keeping the trace open for editing drops the preview but keeps the selection`() = runTest(testDispatcher) {
        seedHiderWithNetwork()
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.drawing.streetStatus != StreetDataStatus.Available || !state.drawing.isActive) {
                state = awaitItem()
            }
            selectLongEdge(viewModel)
            while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()
            viewModel.confirmTrace(Edition.Metric)
            while (state.traceReview == null) state = awaitItem()

            viewModel.resumeTraceEditing()
            while (state.traceReview != null) state = awaitItem()

            assertTrue(state.drawing.isActive)
            assertEquals(1, state.drawing.selectedPaths.size)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a failed send surfaces the error and keeps the preview open`() = runTest(testDispatcher) {
        seedHiderWithNetwork()
        coEvery { fixture.questionRepository.revealPhotoQuestion(any(), any(), any()) } throws IOException("offline")
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.drawing.streetStatus != StreetDataStatus.Available || !state.drawing.isActive) {
                state = awaitItem()
            }
            selectLongEdge(viewModel)
            while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()
            viewModel.confirmTrace(Edition.Metric)
            while (state.traceReview == null) state = awaitItem()

            viewModel.sendTrace()
            while (state.traceReview?.sendFailed != true) state = awaitItem()

            assertNotNull(state.traceReview)
            assertFalse(state.traceReview?.isSending ?: true)
            assertEquals(1, state.drawing.selectedPaths.size)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `an imperial game gates the trace at half a mile`() = runTest(testDispatcher) {
        seedHiderWithNetwork(PhotoTarget.StreetsTraced)
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.drawing.streetStatus != StreetDataStatus.Available || !state.drawing.isActive) {
                state = awaitItem()
            }
            selectShortEdge(viewModel)
            while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()
            assertTrue(state.drawing.lengthMeters < HALF_MILE_METERS)
            assertFalse(state.drawing.canConfirmTrace)

            viewModel.confirmTrace(Edition.Imperial)
            assertEquals(0, fixture.traceImageWriter.writeCount)
            assertNull(state.traceReview)

            selectLongEdge(viewModel)
            while (state.drawing.selectedPaths.size != 2) state = awaitItem()
            assertTrue(state.drawing.lengthMeters >= HALF_MILE_METERS)
            assertTrue(state.drawing.canConfirmTrace)

            viewModel.confirmTrace(Edition.Imperial)
            while (state.traceReview == null) state = awaitItem()
            assertEquals(1, fixture.traceImageWriter.writeCount)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a second confirm while the render is in flight renders once`() = runTest(testDispatcher) {
        seedHiderWithNetwork()
        val render = CompletableDeferred<Unit>()
        fixture.traceImageWriter.renderGate = render
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.drawing.streetStatus != StreetDataStatus.Available || !state.drawing.isActive) {
                state = awaitItem()
            }
            selectLongEdge(viewModel)
            while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()

            viewModel.confirmTrace(Edition.Metric)
            while (!state.drawing.isRendering) state = awaitItem()
            assertFalse(state.drawing.canConfirmTrace)

            viewModel.confirmTrace(Edition.Metric)
            assertEquals(1, fixture.traceImageWriter.writeCount)

            render.complete(Unit)
            while (state.traceReview == null) state = awaitItem()
            assertEquals(1, fixture.traceImageWriter.writeCount)
            assertFalse(state.drawing.isRendering)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a render failure surfaces an error instead of crashing`() = runTest(testDispatcher) {
        seedHiderWithNetwork()
        fixture.traceImageWriter.failure = OutOfMemoryError("bitmap")
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.drawing.streetStatus != StreetDataStatus.Available || !state.drawing.isActive) {
                state = awaitItem()
            }
            selectLongEdge(viewModel)
            while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()

            viewModel.confirmTrace(Edition.Metric)
            while (state.drawing.renderError == null) state = awaitItem()

            assertNull(state.traceReview)
            assertFalse(state.drawing.isRendering)
            assertTrue(state.drawing.canConfirmTrace)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a second send while one is in flight posts once and never revives the preview`() =
        runTest(testDispatcher) {
            seedHiderWithNetwork()
            val send = CompletableDeferred<Unit>()
            coEvery { fixture.questionRepository.revealPhotoQuestion(any(), any(), any()) } coAnswers {
                send.await()
                askedQuestion(PhotoTarget.StreetsTraced)
            }
            val viewModel = fixture.createDrawingViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                while (state.drawing.streetStatus != StreetDataStatus.Available || !state.drawing.isActive) {
                    state = awaitItem()
                }
                selectLongEdge(viewModel)
                while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()
                viewModel.confirmTrace(Edition.Metric)
                while (state.traceReview == null) state = awaitItem()

                viewModel.sendTrace()
                while (state.traceReview?.isSending != true) state = awaitItem()
                viewModel.sendTrace()

                send.complete(Unit)
                while (state.traceReview != null) state = awaitItem()

                viewModel.sendTrace()
                assertNull(viewModel.uiState.value.traceReview)
                coVerify(exactly = 1) {
                    fixture.questionRepository.revealPhotoQuestion("q-1", "player-1", TRACE_IMAGE_URI)
                }
                assertFalse(state.drawing.isActive)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `a trace request for another game is ignored and left parked`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        val request = TraceRequest("game-2", "q-1", PhotoTarget.StreetsTraced)
        fixture.navigationRequestStore.requestTrace(request)
        val otherGamesMap = fixture.createDrawingViewModel()

        assertEquals(request, fixture.navigationRequestStore.pendingTraceRequest.value)
        otherGamesMap.uiState.test {
            assertFalse(awaitItem().drawing.isActive)
            assertEquals(request, fixture.navigationRequestStore.pendingTraceRequest.value)
            cancelAndIgnoreRemainingEvents()
        }

        val ownGame = MapTestFixture(gameUuid = "game-2")
        ownGame.setUp()
        ownGame.sessionRepository.seed(
            PlayerSession("game-2", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        ownGame.navigationRequestStore.requestTrace(request)
        ownGame.createDrawingViewModel().uiState.test {
            var state = awaitItem()
            while (!state.drawing.isActive) state = awaitItem()
            assertEquals("q-1", state.drawing.questionUuid)
            assertNull(ownGame.navigationRequestStore.pendingTraceRequest.value)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `tapping a street selects it and measures a non-zero length`() = runTest(testDispatcher) {
        seedHiderWithNetwork()
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.drawing.streetStatus != StreetDataStatus.Available || !state.drawing.isActive) {
                state = awaitItem()
            }
            selectLongEdge(viewModel)
            while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()

            assertEquals(1, state.drawing.selectedPaths.size)
            assertTrue(state.drawing.lengthMeters > 0.0)
            assertTrue(state.drawing.canConfirmTrace)

            val call = fixture.streetNetworkRepository.fetchCalls.single()
            assertEquals("round-1", call.roundUuid)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `tapping the same street again deselects it`() = runTest(testDispatcher) {
        seedHiderWithNetwork()
        val viewModel = fixture.createDrawingViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.drawing.streetStatus != StreetDataStatus.Available || !state.drawing.isActive) {
                state = awaitItem()
            }
            selectLongEdge(viewModel)
            while (state.drawing.selectedPaths.isEmpty()) state = awaitItem()

            selectLongEdge(viewModel)
            while (state.drawing.selectedPaths.isNotEmpty()) state = awaitItem()

            assertEquals(0.0, state.drawing.lengthMeters, 0.0)
            assertFalse(state.drawing.canConfirmTrace)
            cancelAndIgnoreRemainingEvents()
        }
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

    private fun selectShortEdge(viewModel: DrawingViewModel) =
        viewModel.toggleEdgeAt(SHORT_EDGE_TAP.latitude, SHORT_EDGE_TAP.longitude)

    private fun selectLongEdge(viewModel: DrawingViewModel) =
        viewModel.toggleEdgeAt(LONG_EDGE_TAP.latitude, LONG_EDGE_TAP.longitude)

    private fun askedQuestion(photoTarget: PhotoTarget) = AskedQuestion(
        uuid = "q-1",
        roundUuid = "round-1",
        category = QuestionCategory.Photos,
        askedAt = "2026-01-01T00:00:00Z",
        revealDeadlineAt = "2026-01-01T00:05:00Z",
        revealedAt = null,
        radarAnswer = null,
        thermometerResult = null,
        photoTarget = photoTarget,
    )

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
    }

}
