package fr.gshz.hideandseek.feature.map

import app.cash.turbine.test
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.domain.model.HidingZone
import fr.gshz.hideandseek.domain.model.LocationUpdate
import fr.gshz.hideandseek.domain.model.ZoneCard
import fr.gshz.hideandseek.domain.repository.ZoneChanged
import fr.gshz.hideandseek.domain.repository.ZoneRadiusChanged
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class ZoneViewModelTest {

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

    private fun seedHider() {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
    }

    @Test
    fun `a stale replayed location update does not overwrite the fresher placement seed`() =
        runTest(testDispatcher) {
            seedHider()
            val viewModel = fixture.createZoneViewModel()

            fixture.locationRepository.emit(LocationUpdate("player-1", 10.0, 20.0, "2026-01-01T00:00:10Z"))
            fixture.locationRepository.emit(LocationUpdate("player-1", 11.0, 21.0, "2026-01-01T00:00:05Z"))

            viewModel.uiState.test {
                var state = awaitItem()
                viewModel.enterZonePlacementMode()
                while (state.pendingZonePin == null) state = awaitItem()

                assertEquals(10.0, state.pendingZonePin?.latitude)
                assertEquals(20.0, state.pendingZonePin?.longitude)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `placing a pin and confirming submits the tapped location and chosen radius`() = runTest(testDispatcher) {
        seedHider()
        val viewModel = fixture.createZoneViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.isPlacingZone) state = awaitItem()

            viewModel.enterZonePlacementMode()
            while (!state.isPlacingZone) state = awaitItem()

            viewModel.placeZonePin(10.0, 20.0, stationName = "Alexanderplatz")
            while (state.pendingZonePin == null) state = awaitItem()

            viewModel.selectZoneRadius(1000.0)
            while (state.selectedZoneRadiusMeters != 1000.0) state = awaitItem()

            viewModel.confirmZone()
            while (state.submittedZone == null) state = awaitItem()

            val call = fixture.zoneRepository.calls.single()
            assertEquals("round-1", call.roundUuid)
            assertEquals("player-1", call.playerUuid)
            assertEquals(10.0, call.lat, 0.0)
            assertEquals(20.0, call.lng, 0.0)
            assertEquals(1000.0, call.radiusMeters)
            assertEquals("Alexanderplatz", call.stationName)
            assertFalse(state.isPlacingZone)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `the submitted zone is retained in UI state after confirming`() = runTest(testDispatcher) {
        seedHider()
        fixture.zoneRepository.setHidingZoneResult =
            Result.success(HidingZone(roundUuid = "round-1", lat = 5.0, lng = 6.0, radiusMeters = 500.0))
        val viewModel = fixture.createZoneViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.enterZonePlacementMode()
            viewModel.placeZonePin(5.0, 6.0)
            viewModel.confirmZone()
            while (state.submittedZone == null) state = awaitItem()

            assertEquals(5.0, state.submittedZone?.lat)
            assertEquals(500.0, state.submittedZone?.radiusMeters)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `re-entering placement mode seeds the pending pin from the already-submitted zone`() =
        runTest(testDispatcher) {
            seedHider()
            fixture.zoneRepository.setHidingZoneResult =
                Result.success(HidingZone(roundUuid = "round-1", lat = 5.0, lng = 6.0, radiusMeters = 1000.0))
            val viewModel = fixture.createZoneViewModel()

            viewModel.uiState.test {
                var state = awaitItem()

                viewModel.enterZonePlacementMode()
                viewModel.placeZonePin(5.0, 6.0)
                viewModel.confirmZone()
                while (state.submittedZone == null) state = awaitItem()

                viewModel.enterZonePlacementMode()
                while (!state.isPlacingZone) state = awaitItem()

                assertEquals(5.0, state.pendingZonePin?.latitude)
                assertEquals(6.0, state.pendingZonePin?.longitude)
                assertEquals(1000.0, state.selectedZoneRadiusMeters)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `omitting a radius submits null and accepts the server's resolved default`() = runTest(testDispatcher) {
        seedHider()
        fixture.zoneRepository.setHidingZoneResult =
            Result.success(HidingZone(roundUuid = "round-1", lat = 1.0, lng = 2.0, radiusMeters = 500.0))
        val viewModel = fixture.createZoneViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.enterZonePlacementMode()
            viewModel.placeZonePin(1.0, 2.0)
            viewModel.confirmZone()
            while (state.submittedZone == null) state = awaitItem()

            assertNull(fixture.zoneRepository.calls.single().radiusMeters)
            assertEquals(500.0, state.submittedZone?.radiusMeters)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a zone-radius SSE event updates the current zone radius`() = runTest(testDispatcher) {
        seedHider()
        val viewModel = fixture.createZoneViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            fixture.gameEventRepository.emitZoneRadiusChanged(ZoneRadiusChanged(1234.0))
            while (state.currentZoneRadiusMeters != 1234.0) state = awaitItem()
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a zone SSE event redraws the shared hiding zone at the teammate's station`() = runTest(testDispatcher) {
        seedHider()
        val viewModel = fixture.createZoneViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            fixture.gameEventRepository.emitZoneChanged(ZoneChanged("round-1", 52.52, 13.405, 900.0))
            while (state.submittedZone == null) state = awaitItem()

            val zone = state.submittedZone
            assertEquals("round-1", zone?.roundUuid)
            assertEquals(52.52, zone?.lat)
            assertEquals(13.405, zone?.lng)
            assertEquals(900.0, zone?.radiusMeters)
            assertEquals(900.0, state.currentZoneRadiusMeters)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a hider hunted by seekers plays cards instead of dragging the pin`() = runTest(testDispatcher) {
        seedHider()
        fixture.zoneRepository.setHidingZoneResult =
            Result.success(HidingZone(roundUuid = "round-1", lat = 5.0, lng = 6.0, radiusMeters = 500.0))
        val viewModel = fixture.createZoneViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.enterZonePlacementMode()
            viewModel.placeZonePin(5.0, 6.0)
            viewModel.confirmZone()
            while (state.submittedZone == null) state = awaitItem()

            viewModel.playZoneCard(ZoneCard.ProsperousHome, "content://card.jpg")
            while (fixture.zoneRepository.cardCalls.isEmpty()) state = awaitItem()

            val call = fixture.zoneRepository.cardCalls.single()
            assertEquals("round-1", call.roundUuid)
            assertEquals("player-1", call.playerUuid)
            assertEquals(ZoneCard.ProsperousHome, call.card)
            assertEquals("content://card.jpg", call.cardPhotoUri)
            assertEquals(1, fixture.roundRefreshFlow.refresh.value)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a hider reopening the map gets their zone back`() = runTest(testDispatcher) {
        seedHider()
        fixture.zoneRepository.currentZone = HidingZone(
            roundUuid = "round-1",
            lat = 52.52,
            lng = 13.405,
            radiusMeters = 750.0,
        )
        val viewModel = fixture.createZoneViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.submittedZone == null) state = awaitItem()

            assertEquals(52.52, state.submittedZone?.lat)
            assertEquals(750.0, state.submittedZone?.radiusMeters)
            assertEquals(1, fixture.zoneRepository.currentZoneCalls)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a seeker never asks the server for the hiding zone`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Bob", "token", side = "seeker"),
        )
        val viewModel = fixture.createZoneViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.isPlacingZone) state = awaitItem()

            assertEquals(0, fixture.zoneRepository.currentZoneCalls)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a trace request cancels an open zone placement`() = runTest(testDispatcher) {
        seedHider()
        val viewModel = fixture.createZoneViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            viewModel.enterZonePlacementMode()
            while (!state.isPlacingZone) state = awaitItem()

            fixture.zonePlacementCancelSignal.requestCancellation()
            while (state.isPlacingZone) state = awaitItem()

            assertFalse(state.isPlacingZone)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a round rekey drops the live zone radius`() = runTest(testDispatcher) {
        seedHider()
        val viewModel = fixture.createZoneViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            fixture.gameEventRepository.emitZoneRadiusChanged(ZoneRadiusChanged(1234.0))
            while (state.currentZoneRadiusMeters != 1234.0) state = awaitItem()

            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-2", "player-1", "Alice", "token", side = "hider"),
            )
            while (state.currentZoneRadiusMeters != null) state = awaitItem()
            cancelAndIgnoreRemainingEvents()
        }
    }
}
