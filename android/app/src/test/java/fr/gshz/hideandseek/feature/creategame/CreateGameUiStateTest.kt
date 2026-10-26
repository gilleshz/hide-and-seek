package fr.gshz.hideandseek.feature.creategame

import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.GtfsRoute
import fr.gshz.hideandseek.domain.model.GtfsSourceState
import fr.gshz.hideandseek.domain.model.TransitLine
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class CreateGameUiStateTest {

    private val metro = TransitLine(
        osmId = "1",
        osmType = "relation",
        ref = "M1",
        name = "Metro 1",
        colour = "ff0000",
        routeType = "subway",
        network = "TCL",
        operator = "",
    )
    private val tram = TransitLine(
        osmId = "2",
        osmType = "relation",
        ref = "T1",
        name = "Tram 1",
        colour = "00ff00",
        routeType = "tram",
        network = "TCL",
        operator = "",
    )
    private val school = GtfsRoute(
        sourceUuid = "source-1",
        sourceName = "TCL",
        routeId = "route-jd346",
        shortName = "JD346",
        longName = "Ligne scolaire",
        routeType = 700,
        color = "",
        textColor = "",
    )
    private val source = GtfsSourceState("source-1", "TCL", listOf(school))

    private val base = CreateGameUiState(
        transitLines = listOf(metro, tram),
        gtfsSources = listOf(source),
    )

    @Test
    fun `the fit action is shown for Small and Medium and hidden for Large`() {
        assertTrue(CreateGameUiState(size = GameSize.Small).showFitArea)
        assertTrue(CreateGameUiState(size = GameSize.Medium).showFitArea)
        assertFalse(CreateGameUiState(size = GameSize.Large).showFitArea)
    }

    @Test
    fun `the query filters osm groups and gtfs routes by name and ref`() {
        assertEquals(
            listOf("M1"),
            base.copy(transitLineQuery = "m1").transitLineGroups.map { it.ref },
        )
        assertEquals(
            listOf("JD346"),
            base.copy(transitLineQuery = "jd").uniqueGtfsRoutes.map { it.second.ref },
        )
        assertTrue(base.copy(transitLineQuery = "tcl").transitLineGroups.isNotEmpty())
        assertTrue(base.copy(transitLineQuery = "tcl").uniqueGtfsRoutes.isNotEmpty())
    }

    @Test
    fun `the query combines with the mode filter`() {
        val state = base.copy(transitLineQuery = "M1", transitModeFilter = setOf("tram"))

        assertTrue(state.transitLineGroups.isEmpty())
        assertTrue(state.uniqueGtfsRoutes.isEmpty())
    }

    @Test
    fun `an empty query leaves the mode filter in charge`() {
        val state = base.copy(transitModeFilter = setOf("subway"))

        assertEquals(listOf("M1"), state.transitLineGroups.map { it.ref })
        assertTrue(state.uniqueGtfsRoutes.isEmpty())
    }

    @Test
    fun `bulk selection adds filtered routes under the total limit`() {
        val selected = (1..40).map { "source-1|route-$it" }.toSet()
        val filtered = (41..90).map { "route-$it" }

        val updated = toggleGtfsBulkSelection(selected, "source-1", filtered)

        assertEquals(90, updated?.size ?: -1)
        assertTrue(updated?.contains("source-1|route-90") == true)
    }

    @Test
    fun `bulk selection refuses to exceed the total limit`() {
        val selected = (1..60).map { "source-1|route-$it" }.toSet()
        val filtered = (61..120).map { "route-$it" }

        assertNull(toggleGtfsBulkSelection(selected, "source-1", filtered))
    }

    @Test
    fun `bulk selection counts the total across sources`() {
        val selected = (1..80).map { "source-1|route-$it" }.toSet()

        assertNull(toggleGtfsBulkSelection(selected, "source-2", (1..25).map { "route-$it" }))
        val updated = toggleGtfsBulkSelection(selected, "source-2", (1..20).map { "route-$it" })

        assertEquals(100, updated?.size ?: -1)
        assertTrue(updated?.contains("source-2|route-20") == true)
    }

    @Test
    fun `bulk selection deselects every filtered route`() {
        val filtered = (1..58).map { "route-$it" }
        val selected = filtered.map { "source-1|$it" }.toSet() + "source-1|hidden"

        val updated = toggleGtfsBulkSelection(selected, "source-1", filtered)

        assertEquals(setOf("source-1|hidden"), updated)
    }

    @Test
    fun `individual selection stops at the total limit`() {
        val selected = (1..100).map { "source-1|route-$it" }.toSet()

        assertFalse(canSelectGtfsRoute(selected))
        assertTrue(canSelectGtfsRoute(emptySet()))
        assertTrue(canSelectGtfsRoute(selected - "source-1|route-1"))
    }
}
