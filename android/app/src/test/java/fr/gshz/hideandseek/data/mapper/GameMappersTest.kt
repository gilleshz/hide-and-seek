package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.GameDto
import fr.gshz.hideandseek.data.remote.dto.GtfsLineDto
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class GameMappersTest {

    @Test
    fun `hasGtfsLines is true when the game selected GTFS lines`() {
        val game = game(selectedGtfsLines = listOf(GtfsLineDto(ref = "L1"))).toDomain()

        assertTrue(game.hasGtfsLines)
        assertEquals("L1", game.selectedTransitLines.single().ref)
    }

    @Test
    fun `hasGtfsLines is false without GTFS lines`() {
        assertFalse(game().toDomain().hasGtfsLines)
    }

    private fun game(selectedGtfsLines: List<GtfsLineDto> = emptyList()) = GameDto(
        uuid = "game-1",
        name = "Berlin",
        size = "M",
        edition = "metric",
        createdAt = "2026-01-01T00:00:00Z",
        roundUuid = "round-1",
        selectedGtfsLines = selectedGtfsLines,
    )
}
