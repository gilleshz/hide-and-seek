package fr.gshz.hideandseek.feature.creategame

import fr.gshz.hideandseek.domain.model.TransitLine
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class TransitNetworkSplitTest {

    private fun line(osmId: String, ref: String, network: String) = TransitLine(
        osmType = "relation",
        osmId = osmId,
        ref = ref,
        name = "",
        nameEn = "",
        colour = "",
        routeType = "train",
        network = network,
        operator = "",
    )

    @Test
    fun `a plain network yields one heading`() {
        assertEquals(listOf("ZVV"), splitNetworks("ZVV"))
    }

    @Test
    fun `a joined network yields one heading per member`() {
        assertEquals(listOf("A-Welle", "ZVV"), splitNetworks("A-Welle;ZVV"))
    }

    @Test
    fun `surrounding spaces are trimmed`() {
        assertEquals(listOf("Libero", "CH-VS"), splitNetworks(" Libero ; CH-VS "))
    }

    @Test
    fun `a blank network stays a single empty heading so it falls into Other`() {
        assertEquals(listOf(""), splitNetworks(""))
    }

    @Test
    fun `a line in two networks appears under both`() {
        val state = CreateGameUiState(transitLines = listOf(line("1", "S6", "A-Welle;ZVV")))
        val networks = state.transitLineGroups.map { it.network }

        assertEquals(listOf("A-Welle", "ZVV"), networks.sorted())
    }

    @Test
    fun `the same pair in either order lands under the same two headings`() {
        val state = CreateGameUiState(
            transitLines = listOf(
                line("1", "S6", "Libero;Mobilis"),
                line("2", "S7", "Mobilis;Libero"),
            ),
        )
        val networks = state.transitLineGroups.map { it.network }.distinct().sorted()

        assertEquals(listOf("Libero", "Mobilis"), networks)
    }

    @Test
    fun `selecting a line under one network expands to the same osm id once`() {
        val state = CreateGameUiState(
            transitLines = listOf(line("42", "S6", "A-Welle;ZVV")),
            selectedTransitLines = setOf("42"),
        )

        assertEquals(setOf("42"), state.expandedSelectedLineIds)
    }

    @Test
    fun `a joined network no longer produces a heading of its own`() {
        val state = CreateGameUiState(transitLines = listOf(line("1", "S6", "A-Welle;ZVV")))

        assertTrue(state.transitLineGroups.none { it.network.contains(';') })
    }
}
