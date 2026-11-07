package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.domain.model.TrafficAlert
import fr.gshz.hideandseek.domain.model.TrafficAlertSeverity
import fr.gshz.hideandseek.domain.model.TransitLine
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

    @Test
    fun `computeAlertHighlightRefs maps the alert line refs onto the selected lines`() {
        val lines = listOf(line(ref = "14"), line(ref = "RER A"), line(ref = "9"))
        val alerts = listOf(alert(id = "a1", lineRefs = listOf("rer à"), routeIds = listOf("9")))

        assertEquals(setOf("RER A", "9"), computeAlertHighlightRefs(lines, alerts, "a1"))
    }

    @Test
    fun `computeAlertHighlightRefs is empty without a selected alert or with a network wide alert`() {
        val lines = listOf(line(ref = "14"))
        val alerts = listOf(alert(id = "a1", lineRefs = emptyList(), routeIds = emptyList()))

        assertTrue(computeAlertHighlightRefs(lines, alerts, null).isEmpty())
        assertTrue(computeAlertHighlightRefs(lines, alerts, "missing").isEmpty())
        assertTrue(computeAlertHighlightRefs(lines, alerts, "a1").isEmpty())
    }

    private fun line(ref: String) = TransitLine(
        osmId = "1",
        osmType = "relation",
        ref = ref,
        name = ref,
        colour = "B22E75",
        routeType = "subway",
        network = "",
        operator = "",
    )

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
