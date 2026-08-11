package fr.gshz.hideandseek.data.mapper

import fr.gshz.hideandseek.data.remote.dto.SeekerCandidateMarkerDto
import kotlinx.serialization.json.Json
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Test

class SeekerMarkerMappersTest {

    private val json = Json { ignoreUnknownKeys = true }

    @Test
    fun `a payload with omitted nullable fields decodes without crashing`() {
        val dto = json.decodeFromString<SeekerCandidateMarkerDto>(
            """{"uuid":"m-1","lat":48.85,"lng":2.35}""",
        )

        val marker = dto.toDomain()

        assertEquals("m-1", marker.uuid)
        assertNull(marker.playerUuid)
        assertEquals(48.85, marker.lat, 0.0)
        assertEquals(2.35, marker.lng, 0.0)
    }

    @Test
    fun `a full payload maps all fields to the domain`() {
        val dto = SeekerCandidateMarkerDto("m-2", "player-1", 1.0, 2.0, "2026-01-01T00:00:00Z")

        val marker = dto.toDomain()

        assertEquals("m-2", marker.uuid)
        assertEquals("player-1", marker.playerUuid)
    }
}
