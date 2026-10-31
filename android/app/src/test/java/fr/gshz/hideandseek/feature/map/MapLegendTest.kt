package fr.gshz.hideandseek.feature.map

import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.domain.model.ConstraintMode
import fr.gshz.hideandseek.domain.model.HidingZone
import fr.gshz.hideandseek.domain.model.ManualConstraint
import fr.gshz.hideandseek.domain.model.SeekerMarker
import fr.gshz.hideandseek.domain.model.Side
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Test

class MapLegendTest {

    @Test
    fun `a seeker with a band and manual constraints lists the seeker layers`() {
        val state = MapUiState(
            side = Side.Seeker,
            possibleAreaGeoJson = "area",
            stationBandGeoJson = "band",
            manualConstraints = listOf(
                ManualConstraint("constraint-1", ConstraintMode.Include, "{}", "search-area", createdByName = null),
            ),
            seekerMarkers = listOf(SeekerMarker("marker-1", null, 46.52, 6.63)),
        )

        val entries = legendEntries(state)

        assertEquals(
            listOf(
                R.string.map_legend_possible_area,
                R.string.map_legend_edge_zone,
                R.string.map_legend_excluded,
                R.string.map_legend_search_area,
                R.string.map_legend_suspected_station,
                R.string.map_legend_players,
            ),
            entries.map { it.label },
        )
        assertEquals(R.string.map_legend_edge_zone_detail, entries[1].detail)
    }

    @Test
    fun `a seeker without a band omits the band row`() {
        val state = MapUiState(side = Side.Seeker, possibleAreaGeoJson = "area")

        assertEquals(
            listOf(
                R.string.map_legend_possible_area,
                R.string.map_legend_excluded,
                R.string.map_legend_players,
            ),
            legendEntries(state).map { it.label },
        )
    }

    @Test
    fun `a hider with a zone lists the hiding zone`() {
        val state = MapUiState(
            side = Side.Hider,
            submittedZone = HidingZone("round-1", 46.52, 6.63, 500.0),
        )

        assertEquals(
            listOf(
                R.string.map_legend_excluded,
                R.string.map_legend_hiding_zone,
                R.string.map_legend_players,
            ),
            legendEntries(state).map { it.label },
        )
    }

    @Test
    fun `a hider with a shared possible area lists it too`() {
        val state = MapUiState(
            side = Side.Hider,
            possibleAreaGeoJson = "area",
            submittedZone = HidingZone("round-1", 46.52, 6.63, 500.0),
        )

        assertEquals(
            listOf(
                R.string.map_legend_possible_area,
                R.string.map_legend_excluded,
                R.string.map_legend_hiding_zone,
                R.string.map_legend_players,
            ),
            legendEntries(state).map { it.label },
        )
    }
}
