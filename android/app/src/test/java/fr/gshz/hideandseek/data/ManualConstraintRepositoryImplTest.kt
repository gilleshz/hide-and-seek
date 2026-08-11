package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.AddManualConstraintRequest
import fr.gshz.hideandseek.data.remote.dto.ManualConstraintDto
import fr.gshz.hideandseek.domain.model.ConstraintMode
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.mockk
import java.io.IOException
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNotNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.Test

class ManualConstraintRepositoryImplTest {

    private val api = mockk<HideAndSeekApi>(relaxed = true)
    private val connectionStore = mockk<ConnectionStore>()
    private val repository = ManualConstraintRepositoryImpl(api, connectionStore)

    private val base = "/api/rounds/round-1/possible-area-constraints"

    @Test
    fun `getManualConstraints builds the collection URL and maps DTOs to domain`() = runTest {
        coEvery { connectionStore.current() } returns ConnectionConfig("https://api.example.com", "key")
        coEvery { api.getManualConstraints("https://api.example.com$base") } returns listOf(
            ManualConstraintDto(
                uuid = "c-1",
                mode = "exclude",
                geoJson = "geo-1",
                source = "manual",
                label = "Downtown",
                createdByName = "Bob",
            ),
        )

        val constraints = repository.getManualConstraints("round-1")

        assertEquals(1, constraints.size)
        assertEquals("c-1", constraints.first().uuid)
        assertEquals(ConstraintMode.Exclude, constraints.first().mode)
        assertEquals("Downtown", constraints.first().label)
        assertEquals("Bob", constraints.first().createdByName)
    }

    @Test
    fun `addManualConstraint posts the player, geometry and mode and maps the response`() = runTest {
        coEvery { connectionStore.current() } returns ConnectionConfig("https://api.example.com", "key")
        coEvery {
            api.addManualConstraint(
                "https://api.example.com$base",
                AddManualConstraintRequest("player-1", "geo-2", "include", "Center"),
            )
        } returns ManualConstraintDto(uuid = "c-2", mode = "include", geoJson = "geo-2", label = "Center")

        val constraint = repository.addManualConstraint(
            "round-1", "player-1", "geo-2", ConstraintMode.Include, "Center",
        )

        assertEquals("c-2", constraint.uuid)
        assertEquals(ConstraintMode.Include, constraint.mode)
        coVerify {
            api.addManualConstraint(
                "https://api.example.com$base",
                AddManualConstraintRequest("player-1", "geo-2", "include", "Center"),
            )
        }
    }

    @Test
    fun `deleteManualConstraint builds the item URL and sends the acting player as a query param`() = runTest {
        coEvery { connectionStore.current() } returns ConnectionConfig("https://api.example.com", "key")

        repository.deleteManualConstraint("round-1", "c-3", "player-1")

        coVerify { api.deleteManualConstraint("https://api.example.com$base/c-3", "player-1") }
    }

    @Test
    fun `getManualConstraints propagates a network failure to the caller`() = runTest {
        coEvery { connectionStore.current() } returns ConnectionConfig("https://api.example.com", "key")
        coEvery { api.getManualConstraints(any()) } throws IOException("boom")

        var thrown: Throwable? = null
        try {
            repository.getManualConstraints("round-1")
        } catch (e: IOException) {
            thrown = e
        }

        assertNotNull(thrown)
    }

    @Test
    fun `getManualConstraints without a connection throws NotConnectedException`() = runTest {
        coEvery { connectionStore.current() } returns null

        val result = runCatching { repository.getManualConstraints("round-1") }

        assertTrue(result.exceptionOrNull() is NotConnectedException)
    }
}
