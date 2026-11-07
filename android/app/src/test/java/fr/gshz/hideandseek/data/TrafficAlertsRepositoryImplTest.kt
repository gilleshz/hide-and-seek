package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.TrafficAlertDto
import fr.gshz.hideandseek.data.remote.dto.TrafficAlertsDto
import fr.gshz.hideandseek.domain.model.TrafficAlertSeverity
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.mockk
import java.io.IOException
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

class TrafficAlertsRepositoryImplTest {

    private val api = mockk<HideAndSeekApi>(relaxed = true)
    private val connectionStore = mockk<ConnectionStore>()
    private lateinit var repository: TrafficAlertsRepositoryImpl

    @BeforeEach
    fun setUp() {
        coEvery { connectionStore.current() } returns ConnectionConfig("https://api.example.com/", "key")
        repository = TrafficAlertsRepositoryImpl(api, connectionStore)
    }

    @Test
    fun `getTrafficAlerts calls the game endpoint and maps every field`() = runTest {
        coEvery { api.getTrafficAlerts("https://api.example.com/api/games/game-1/traffic-alerts") } returns
            TrafficAlertsDto(
                serverNow = 1_760_000_000L,
                alerts = listOf(
                    TrafficAlertDto(
                        id = "alert-1",
                        channel = "siri",
                        severity = "severe",
                        title = "Line 14 suspended",
                        body = "A broken signal at Chatelet.",
                        lineRefs = listOf("14"),
                        routeIds = listOf("route-14"),
                        stopIds = listOf("stop-1"),
                        validFrom = 1_759_990_000L,
                        validUntil = null,
                        source = "operator",
                    ),
                ),
            )

        val result = repository.getTrafficAlerts("game-1")

        assertEquals(1_760_000_000L, result.serverNow)
        val alert = result.alerts.single()
        assertEquals("alert-1", alert.id)
        assertEquals("siri", alert.channel)
        assertEquals(TrafficAlertSeverity.Severe, alert.severity)
        assertEquals("Line 14 suspended", alert.title)
        assertEquals("A broken signal at Chatelet.", alert.body)
        assertEquals(listOf("14"), alert.lineRefs)
        assertEquals(listOf("route-14"), alert.routeIds)
        assertEquals(listOf("stop-1"), alert.stopIds)
        assertEquals(1_759_990_000L, alert.validFrom)
        assertNull(alert.validUntil)
        assertEquals("operator", alert.source)
        coVerify { api.getTrafficAlerts("https://api.example.com/api/games/game-1/traffic-alerts") }
    }

    @Test
    fun `getTrafficAlerts keeps null validity windows when the backend omits them`() = runTest {
        coEvery { api.getTrafficAlerts(any()) } returns
            TrafficAlertsDto(
                serverNow = 10L,
                alerts = listOf(TrafficAlertDto(id = "a", severity = "info")),
            )

        val alert = repository.getTrafficAlerts("game-1").alerts.single()

        assertNull(alert.validFrom)
        assertNull(alert.validUntil)
        assertEquals(TrafficAlertSeverity.Info, alert.severity)
        assertTrue(alert.lineRefs.isEmpty())
    }

    @Test
    fun `getTrafficAlerts maps an unknown severity to info`() = runTest {
        coEvery { api.getTrafficAlerts(any()) } returns
            TrafficAlertsDto(alerts = listOf(TrafficAlertDto(id = "a", severity = "critical")))

        assertEquals(TrafficAlertSeverity.Info, repository.getTrafficAlerts("game-1").alerts.single().severity)
    }

    @Test
    fun `getTrafficAlerts propagates a network failure`() = runTest {
        coEvery { api.getTrafficAlerts(any()) } throws IOException("offline")

        val result = runCatching { repository.getTrafficAlerts("game-1") }

        assertTrue(result.exceptionOrNull() is IOException)
    }

    @Test
    fun `getTrafficAlerts throws when not connected`() = runTest {
        coEvery { connectionStore.current() } returns null

        val result = runCatching { repository.getTrafficAlerts("game-1") }

        assertTrue(result.exceptionOrNull() is NotConnectedException)
    }
}
