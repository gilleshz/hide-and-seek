package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.domain.model.TransitDeparture
import fr.gshz.hideandseek.domain.model.TransitDepartureGroup
import fr.gshz.hideandseek.domain.model.TransitDepartures
import fr.gshz.hideandseek.fake.httpException
import java.io.IOException
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class TimetableViewModelTest {

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
    fun `opening shows the loading state then the departures`() = runTest(testDispatcher) {
        val gate = CompletableDeferred<Unit>()
        fixture.gameRepository.getStationDeparturesGate = gate
        fixture.gameRepository.getStationDeparturesResult = Result.success(departures())
        val viewModel = fixture.createTimetableViewModel()

        viewModel.open(STATION_ID, "ALGER")
        assertTrue(viewModel.uiState.value.isLoading)

        gate.complete(Unit)
        runCurrent()

        val state = viewModel.uiState.value
        assertFalse(state.isLoading)
        assertEquals("L1", state.groups.single().lineRef)
        assertEquals(listOf(fixture.gameUuid to STATION_ID), fixture.gameRepository.getStationDeparturesCalls)
        viewModel.close()
    }

    @Test
    fun `the full day toggle requests the whole service day`() = runTest(testDispatcher) {
        val viewModel = fixture.createTimetableViewModel()

        viewModel.open(STATION_ID, "ALGER")
        runCurrent()
        viewModel.toggleFullDay()
        runCurrent()

        assertTrue(viewModel.uiState.value.showFullDay)
        assertEquals(listOf(false, true), fixture.gameRepository.getStationDeparturesFullDayCalls)
        viewModel.close()
    }

    @Test
    fun `opening another station returns to the next departures view`() = runTest(testDispatcher) {
        val viewModel = fixture.createTimetableViewModel()

        viewModel.open(STATION_ID, "ALGER")
        runCurrent()
        viewModel.toggleFullDay()
        runCurrent()
        assertTrue(viewModel.uiState.value.showFullDay)

        viewModel.open(OTHER_STATION, "GARE")
        runCurrent()

        assertFalse(viewModel.uiState.value.showFullDay)
        viewModel.close()
    }

    @Test
    fun `polling refreshes every thirty seconds`() = runTest(testDispatcher) {
        val viewModel = fixture.createTimetableViewModel()

        viewModel.open(STATION_ID, "ALGER")
        runCurrent()
        assertEquals(1, fixture.gameRepository.getStationDeparturesCalls.size)

        advanceTimeBy(POLL_INTERVAL_MS + 1)
        runCurrent()

        assertEquals(2, fixture.gameRepository.getStationDeparturesCalls.size)
        viewModel.close()
    }

    @Test
    fun `closing stops the polling`() = runTest(testDispatcher) {
        val viewModel = fixture.createTimetableViewModel()

        viewModel.open(STATION_ID, "ALGER")
        runCurrent()
        viewModel.close()
        advanceTimeBy(POLL_INTERVAL_MS * 2)
        runCurrent()

        assertEquals(1, fixture.gameRepository.getStationDeparturesCalls.size)
        assertFalse(viewModel.uiState.value.isVisible)
    }

    @Test
    fun `a missing timetable stops polling with unavailable`() = runTest(testDispatcher) {
        fixture.gameRepository.getStationDeparturesResult = Result.failure(httpException(404))
        val viewModel = fixture.createTimetableViewModel()

        viewModel.open(STATION_ID, "ALGER")
        runCurrent()
        advanceTimeBy(POLL_INTERVAL_MS * 2)
        runCurrent()

        assertEquals(TimetableError.Unavailable, viewModel.uiState.value.error)
        assertEquals(1, fixture.gameRepository.getStationDeparturesCalls.size)
    }

    @Test
    fun `a network failure keeps polling and keeps the previous departures`() = runTest(testDispatcher) {
        fixture.gameRepository.getStationDeparturesResult = Result.success(departures())
        val viewModel = fixture.createTimetableViewModel()
        viewModel.open(STATION_ID, "ALGER")
        runCurrent()

        fixture.gameRepository.getStationDeparturesResult = Result.failure(IOException("offline"))
        advanceTimeBy(POLL_INTERVAL_MS + 1)
        runCurrent()

        val state = viewModel.uiState.value
        assertEquals(TimetableError.Network, state.error)
        assertEquals(1, state.groups.size)
        assertEquals(2, fixture.gameRepository.getStationDeparturesCalls.size)
        viewModel.close()
    }

    private fun departures() = TransitDepartures(
        stationId = STATION_ID,
        stationName = "ALGER",
        timezone = "Europe/Paris",
        serverNow = 1_760_000_000L,
        groups = listOf(
            TransitDepartureGroup(
                routeId = "1",
                lineRef = "L1",
                lineName = "MOULINS TOURNEBRIDE - LA CORCHADE",
                colour = "B22E75",
                headsign = "La Corchade",
                directionId = 0,
                realtime = true,
                departures = listOf(TransitDeparture(1_760_000_300L, 300, true, 60, "La Corchade")),
            ),
        ),
    )

    private companion object {
        const val STATION_ID = "S_49.0944_6.1354"
        const val OTHER_STATION = "S_49.1107_6.1712"
        const val POLL_INTERVAL_MS = 30_000L
    }
}
