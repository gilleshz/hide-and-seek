package fr.gshz.hideandseek.data

import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.LocationPingRequest
import fr.gshz.hideandseek.data.remote.dto.LocationPingResponse
import fr.gshz.hideandseek.domain.model.DeviceLocation
import fr.gshz.hideandseek.domain.repository.GameEventRepository
import fr.gshz.hideandseek.fake.httpException
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.every
import io.mockk.mockk
import java.util.function.Consumer
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test
import retrofit2.HttpException

class LocationRepositoryImplTest {

    private val api = mockk<HideAndSeekApi>()
    private val connectionStore = mockk<ConnectionStore>()
    private val gameEventRepository = mockk<GameEventRepository>(relaxed = true)
    private val context = mockk<Context>(relaxed = true)
    private val locationManager = mockk<LocationManager>(relaxed = true)

    private val repository =
        LocationRepositoryImpl(api, connectionStore, gameEventRepository, context)

    @BeforeEach
    fun setUpLocationManager() {
        every { context.getSystemService(LocationManager::class.java) } returns locationManager
    }

    @Test
    fun `postLocationPing sends the round and player's coordinates to the API`() = runTest {
        val expectedUrl = "https://api.example.com/api/rounds/round-1/location"
        val expectedBody = LocationPingRequest("player-1", 12.3, 45.6)
        coEvery { connectionStore.current() } returns ConnectionConfig("https://api.example.com", "key")
        coEvery { api.postLocation(expectedUrl, expectedBody) } returns
            LocationPingResponse("player-1", "round-1", "2026-01-01T00:00:00Z")

        val endgame = repository.postLocationPing("round-1", "player-1", 12.3, 45.6)

        coVerify { api.postLocation(expectedUrl, expectedBody) }
        assertFalse(endgame)
    }

    @Test
    fun `postLocationPing reports when this ingest started the endgame`() = runTest {
        val expectedUrl = "https://api.example.com/api/rounds/round-1/location"
        val expectedBody = LocationPingRequest("player-1", 12.3, 45.6)
        coEvery { connectionStore.current() } returns ConnectionConfig("https://api.example.com", "key")
        coEvery { api.postLocation(expectedUrl, expectedBody) } returns
            LocationPingResponse("player-1", "round-1", "2026-01-01T00:00:00Z", endgame = true)

        val endgame = repository.postLocationPing("round-1", "player-1", 12.3, 45.6)

        assertTrue(endgame)
    }

    @Test
    fun `postLocationPing forwards the device altitude to the API`() = runTest {
        val expectedUrl = "https://api.example.com/api/rounds/round-1/location"
        val expectedBody = LocationPingRequest("player-1", 12.3, 45.6, altitude = 210.5)
        coEvery { connectionStore.current() } returns ConnectionConfig("https://api.example.com", "key")
        coEvery { api.postLocation(expectedUrl, expectedBody) } returns
            LocationPingResponse("player-1", "round-1", "2026-01-01T00:00:00Z")

        repository.postLocationPing("round-1", "player-1", 12.3, 45.6, altitude = 210.5)

        coVerify { api.postLocation(expectedUrl, expectedBody) }
    }

    @Test
    fun `postLocationPing propagates an HTTP error from the API`() = runTest {
        coEvery { connectionStore.current() } returns ConnectionConfig("https://api.example.com", "key")
        coEvery { api.postLocation(any(), any()) } throws httpException(500)

        val result = runCatching { repository.postLocationPing("round-1", "player-1", 1.0, 2.0) }

        assertTrue(result.isFailure)
        assertTrue(result.exceptionOrNull() is HttpException)
    }

    @Test
    fun `postLocationPing without a connection throws NotConnectedException`() = runTest {
        coEvery { connectionStore.current() } returns null

        val result = runCatching { repository.postLocationPing("round-1", "player-1", 1.0, 2.0) }

        assertTrue(result.exceptionOrNull() is NotConnectedException)
    }

    @Test
    fun `fresh fix delivers the location from the consumer`() = runTest {
        val fix = locationFix(12.3, 45.6)
        every {
            locationManager.getCurrentLocation(LocationManager.GPS_PROVIDER, any(), any(), any())
        } answers {
            (args[3] as Consumer<Location>).accept(fix)
        }

        val result = repository.getCurrentLocation()

        assertEquals(DeviceLocation(12.3, 45.6, null), result)
    }

    @Test
    fun `fresh fix returns null when no fix arrives within the timeout`() = runTest {
        val result = repository.getCurrentLocation()

        assertNull(result)
    }

    @Test
    fun `fresh fix without the location permission returns null`() = runTest {
        every { context.checkPermission(any(), any(), any()) } returns PackageManager.PERMISSION_DENIED

        val result = repository.getCurrentLocation()

        assertNull(result)
    }

    private fun locationFix(lat: Double, lng: Double): Location = mockk<Location>(relaxed = true).apply {
        every { latitude } returns lat
        every { longitude } returns lng
    }
}
