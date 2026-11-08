package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.domain.model.TrafficAlert
import fr.gshz.hideandseek.domain.model.TrafficAlertSeverity
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class TrafficAlertsUiTest {

    @Test
    fun `normalizeLineRef ignores case accents and surrounding spaces`() {
        assertEquals("14", normalizeLineRef(" 14 "))
        assertEquals("rer a", normalizeLineRef("RER À"))
        assertEquals(normalizeLineRef("Ligne 4"), normalizeLineRef("ligne 4"))
    }

    @Test
    fun `alertsForLine matches the line ref case and accent insensitively`() {
        val alerts = listOf(
            alert(id = "a1", lineRefs = listOf("RER À")),
            alert(id = "a2", lineRefs = listOf("14")),
            alert(id = "a3", lineRefs = emptyList()),
        )

        assertEquals(listOf("a2"), alerts.alertsForLine("14").map { it.id })
        assertEquals(listOf("a1"), alerts.alertsForLine("rer a").map { it.id })
        assertTrue(alerts.alertsForLine("9").isEmpty())
    }

    private fun alert(
        id: String,
        lineRefs: List<String>,
        routeIds: List<String> = emptyList(),
    ) = TrafficAlert(
        id = id,
        channel = "siri",
        severity = TrafficAlertSeverity.Info,
        title = "title",
        body = "",
        lineRefs = lineRefs,
        routeIds = routeIds,
        stopIds = emptyList(),
        validFrom = null,
        validUntil = null,
        source = "operator",
    )
}
