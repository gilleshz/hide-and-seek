package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.domain.model.TrafficAlert
import fr.gshz.hideandseek.domain.model.TrafficAlertSeverity
import fr.gshz.hideandseek.domain.model.TrafficAlerts
import java.io.IOException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.collect
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class MapSessionViewModelTrafficAlertsTest {

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
    fun `traffic alerts are polled on start and exposed in the ui state`() = runTest(testDispatcher) {
        fixture.trafficAlertsRepository.result =
            Result.success(TrafficAlerts(serverNow = 1_760_000_000L, alerts = listOf(alert("a1"))))
        val viewModel = fixture.createSessionViewModel()
        backgroundScope.launch(testDispatcher) { viewModel.uiState.collect {} }
        runCurrent()

        val state = viewModel.uiState.value
        assertEquals(listOf("game-1"), fixture.trafficAlertsRepository.calls)
        assertEquals("a1", state.trafficAlerts.alerts.single().id)
        assertEquals(1_760_000_000L, state.trafficAlerts.serverNow)
    }

    @Test
    fun `traffic alerts are polled again every thirty seconds`() = runTest(testDispatcher) {
        fixture.trafficAlertsRepository.result =
            Result.success(TrafficAlerts(serverNow = 0, alerts = listOf(alert("a1"))))
        val viewModel = fixture.createSessionViewModel()
        backgroundScope.launch(testDispatcher) { viewModel.uiState.collect {} }
        runCurrent()
        assertEquals(1, fixture.trafficAlertsRepository.calls.size)

        advanceTimeBy(POLL_INTERVAL_MS + 1)
        runCurrent()

        assertEquals(2, fixture.trafficAlertsRepository.calls.size)
        advanceTimeBy(POLL_INTERVAL_MS + 1)
        runCurrent()
        assertEquals(3, fixture.trafficAlertsRepository.calls.size)
    }

    @Test
    fun `a failed poll keeps the last good alert list`() = runTest(testDispatcher) {
        fixture.trafficAlertsRepository.result =
            Result.success(TrafficAlerts(serverNow = 0, alerts = listOf(alert("a1"))))
        val viewModel = fixture.createSessionViewModel()
        backgroundScope.launch(testDispatcher) { viewModel.uiState.collect {} }
        runCurrent()
        assertEquals("a1", viewModel.uiState.value.trafficAlerts.alerts.single().id)

        fixture.trafficAlertsRepository.result = Result.failure(IOException("offline"))
        advanceTimeBy(POLL_INTERVAL_MS + 1)
        runCurrent()

        assertEquals(2, fixture.trafficAlertsRepository.calls.size)
        assertEquals("a1", viewModel.uiState.value.trafficAlerts.alerts.single().id)
    }

    @Test
    fun `a later successful poll replaces the alert list`() = runTest(testDispatcher) {
        fixture.trafficAlertsRepository.result =
            Result.success(TrafficAlerts(serverNow = 0, alerts = listOf(alert("a1"))))
        val viewModel = fixture.createSessionViewModel()
        backgroundScope.launch(testDispatcher) { viewModel.uiState.collect {} }
        runCurrent()

        fixture.trafficAlertsRepository.result =
            Result.success(TrafficAlerts(serverNow = 0, alerts = listOf(alert("a2"), alert("a3"))))
        advanceTimeBy(POLL_INTERVAL_MS + 1)
        runCurrent()

        assertEquals(listOf("a2", "a3"), viewModel.uiState.value.trafficAlerts.alerts.map { it.id })
    }

    @Test
    fun `initial poll counts every alert as unseen and marking seen clears the count`() = runTest(testDispatcher) {
        fixture.trafficAlertsRepository.result =
            Result.success(TrafficAlerts(serverNow = 0, alerts = listOf(alert("a1"), alert("a2"))))
        val viewModel = fixture.createSessionViewModel()
        backgroundScope.launch(testDispatcher) { viewModel.uiState.collect {} }
        runCurrent()

        assertEquals(2, awaitUnseenCount(viewModel, 2).trafficAlerts.unseenCount)

        viewModel.markTrafficAlertsSeen()
        runCurrent()

        val state = awaitUnseenCount(viewModel, 0)
        assertEquals(0, state.trafficAlerts.unseenCount)
        assertEquals(listOf("a1", "a2"), state.trafficAlerts.alerts.map { it.id })
    }

    @Test
    fun `a later poll with a new alert counts only the new one as unseen`() = runTest(testDispatcher) {
        fixture.trafficAlertsRepository.result =
            Result.success(TrafficAlerts(serverNow = 0, alerts = listOf(alert("a1"), alert("a2"))))
        val viewModel = fixture.createSessionViewModel()
        backgroundScope.launch(testDispatcher) { viewModel.uiState.collect {} }
        runCurrent()
        awaitUnseenCount(viewModel, 2)
        viewModel.markTrafficAlertsSeen()
        runCurrent()
        awaitUnseenCount(viewModel, 0)

        fixture.trafficAlertsRepository.result =
            Result.success(TrafficAlerts(serverNow = 0, alerts = listOf(alert("a1"), alert("a2"), alert("a3"))))
        advanceTimeBy(POLL_INTERVAL_MS + 1)
        runCurrent()

        val state = awaitUnseenCount(viewModel, 1)
        assertEquals(3, state.trafficAlerts.alerts.size)
        assertEquals(1, state.trafficAlerts.unseenCount)
    }

    private fun TestScope.awaitUnseenCount(viewModel: MapSessionViewModel, expected: Int): MapSessionUiState {
        var state = viewModel.uiState.value
        var guard = 0
        while (state.trafficAlerts.unseenCount != expected && guard++ < 100) {
            runCurrent()
            state = viewModel.uiState.value
        }
        return state
    }

    private fun alert(id: String) = TrafficAlert(
        id = id,
        channel = "siri",
        severity = TrafficAlertSeverity.Warning,
        title = "Works on line 14",
        body = "Expect delays.",
        lineRefs = listOf("14"),
        routeIds = emptyList(),
        stopIds = emptyList(),
        validFrom = null,
        validUntil = null,
        source = "operator",
    )

    private companion object {
        const val POLL_INTERVAL_MS = 30_000L
    }
}
