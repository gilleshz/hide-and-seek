package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.AddSeekerCandidateMarkerRequest
import fr.gshz.hideandseek.data.remote.dto.SeekerCandidateMarkerDto
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.mockk
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class SeekerMarkerRepositoryImplTest {

    private val api = mockk<HideAndSeekApi>(relaxed = true)
    private val connectionStore = mockk<ConnectionStore>()
    private val repository = SeekerMarkerRepositoryImpl(api, connectionStore)

    private val base = "/api/rounds/round-1/seeker-candidate-markers"

    @Test
    fun `listMarkers builds the collection URL and maps DTOs to domain`() = runTest {
        coEvery { connectionStore.current() } returns ConnectionConfig("https://api.example.com", "key")
        coEvery { api.getSeekerCandidateMarkers("https://api.example.com$base") } returns
            listOf(SeekerCandidateMarkerDto("m-1", "player-1", 1.0, 2.0, "2026-01-01T00:00:00Z"))

        val markers = repository.listMarkers("round-1")

        assertEquals(1, markers.size)
        assertEquals("m-1", markers.first().uuid)
        assertEquals("player-1", markers.first().playerUuid)
        assertEquals(1.0, markers.first().lat, 0.0)
        assertEquals(2.0, markers.first().lng, 0.0)
    }

    @Test
    fun `addMarker posts the player and coordinates and maps the response`() = runTest {
        coEvery { connectionStore.current() } returns ConnectionConfig("https://api.example.com", "key")
        coEvery {
            api.addSeekerCandidateMarker(
                "https://api.example.com$base",
                AddSeekerCandidateMarkerRequest("player-1", 3.0, 4.0),
            )
        } returns SeekerCandidateMarkerDto("m-2", "player-1", 3.0, 4.0, "2026-01-01T00:00:00Z")

        val marker = repository.addMarker("round-1", "player-1", 3.0, 4.0)

        assertEquals("m-2", marker.uuid)
        coVerify {
            api.addSeekerCandidateMarker(
                "https://api.example.com$base",
                AddSeekerCandidateMarkerRequest("player-1", 3.0, 4.0),
            )
        }
    }

    @Test
    fun `deleteMarker builds the item URL`() = runTest {
        coEvery { connectionStore.current() } returns ConnectionConfig("https://api.example.com", "key")

        repository.deleteMarker("round-1", "m-3")

        coVerify { api.deleteSeekerCandidateMarker("https://api.example.com$base/m-3") }
    }

    @Test
    fun `listMarkers without a connection throws NotConnectedException`() = runTest {
        coEvery { connectionStore.current() } returns null

        val result = runCatching { repository.listMarkers("round-1") }

        assertTrue(result.exceptionOrNull() is NotConnectedException)
    }
}
