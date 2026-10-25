package fr.gshz.hideandseek.feature.creategame

import fr.gshz.hideandseek.domain.model.TransitCatalogSuggestion
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class GtfsCatalogSortingTest {

    @Test
    fun `too large suggestions are last and keep the backend order otherwise`() {
        val suggestions = listOf(
            suggestion("small", 100_000_000),
            suggestion("big", 250_000_000),
            suggestion("unknown", null),
            suggestion("small2", 150_000_000),
        )

        val sorted = catalogSuggestionsInDisplayOrder(suggestions)

        assertEquals(listOf("small", "unknown", "small2", "big"), sorted.map { it.id })
        assertTrue(sorted.last().isTooLarge)
        assertFalse(sorted.first().isTooLarge)
    }

    @Test
    fun `a suggestion at the cap is still selectable`() {
        assertFalse(suggestion("cap", GTFS_MAX_DOWNLOAD_BYTES).isTooLarge)
        assertTrue(suggestion("over", GTFS_MAX_DOWNLOAD_BYTES + 1).isTooLarge)
    }

    private fun suggestion(id: String, sizeBytes: Long?) = TransitCatalogSuggestion(
        id = id,
        provider = "provider",
        name = "name",
        municipality = "city",
        countryCode = "FR",
        licenseUrl = "",
        downloadUrl = "https://example.test/feed.zip",
        fallbackUrl = "",
        realtimeUrl = "",
        live = false,
        scope = "regional",
        sizeBytes = sizeBytes,
    )
}
