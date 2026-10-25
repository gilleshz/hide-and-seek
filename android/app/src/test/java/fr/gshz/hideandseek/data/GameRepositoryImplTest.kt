package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.device.DeviceIdentity
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.domain.model.BoundingBox
import io.mockk.coEvery
import io.mockk.mockk
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test
import retrofit2.HttpException
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory

class GameRepositoryImplTest {

    private lateinit var server: MockWebServer
    private lateinit var repository: GameRepositoryImpl

    @BeforeEach
    fun setUp() {
        server = MockWebServer()
        server.start()
        val json = Json { ignoreUnknownKeys = true }
        val retrofit = Retrofit.Builder()
            .baseUrl(server.url("/"))
            .client(OkHttpClient())
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()
        val api = retrofit.create(HideAndSeekApi::class.java)
        val connectionStore = mockk<ConnectionStore>()
        coEvery { connectionStore.current() } returns
            ConnectionConfig(server.url("/").toString().trimEnd('/'), "key")
        repository = GameRepositoryImpl(api, api, connectionStore, FakeDeviceIdentity)
    }

    @AfterEach
    fun tearDown() {
        server.shutdown()
    }

    @Test
    fun `getTransitOverlay maps a 204 no content to null`() = runTest {
        server.enqueue(MockResponse().setResponseCode(204))

        assertNull(repository.getTransitOverlay("game-1"))
    }

    @Test
    fun `getTransitOverlay returns the GeoJSON body on 200`() = runTest {
        val overlay = """{"type":"FeatureCollection","features":[]}"""
        server.enqueue(MockResponse().setResponseCode(200).setBody(overlay))

        assertEquals(overlay, repository.getTransitOverlay("game-1"))
    }

    @Test
    fun `getTransitOverlay propagates an error status as HttpException`() = runTest {
        server.enqueue(MockResponse().setResponseCode(401))

        val result = runCatching { repository.getTransitOverlay("game-1") }

        val exception = result.exceptionOrNull()
        assertTrue(exception is HttpException)
        assertEquals(401, (exception as HttpException).code())
    }
    @Test
    fun `joinGame sends the player name and device key`() = runTest {
        val joinBody = """
            {"playerUuid":"p1","displayName":"Alice","gameUuid":"g1",
             "roundUuid":"r1","mercureToken":"t","topics":[]}
        """.trimIndent()
        server.enqueue(MockResponse().setResponseCode(201).setBody(joinBody))

        repository.joinGame("g1", "Alice")

        assertEquals(
            """{"name":"Alice","deviceKey":"test-device-key"}""",
            server.takeRequest().body.readUtf8(),
        )
    }

    @Test
    fun `searchTransitCatalog sends the bbox with an encoded query and maps the payload`() = runTest {
        server.enqueue(MockResponse().setResponseCode(200).setBody(CATALOG_JSON))

        val results = repository.searchTransitCatalog(
            BoundingBox(swLat = SW_LAT, swLng = SW_LNG, neLat = NE_LAT, neLng = NE_LNG),
            "Lausanne gare",
        )

        assertEquals(
            "/api/transit/catalog?bbox=$SW_LAT,$SW_LNG,$NE_LAT,$NE_LNG&q=Lausanne+gare",
            server.takeRequest().path,
        )
        val suggestion = results.single()
        assertEquals("ch-1", suggestion.id)
        assertEquals("opentransportdata.swiss", suggestion.provider)
        assertEquals("Vaud", suggestion.municipality)
        assertEquals("CH", suggestion.countryCode)
        assertEquals("", suggestion.licenseUrl)
        assertEquals("https://example.test/feed.zip", suggestion.downloadUrl)
        assertEquals("", suggestion.fallbackUrl)
        assertEquals("", suggestion.realtimeUrl)
        assertTrue(suggestion.live)
        assertEquals("regional", suggestion.scope)
        assertEquals(151338107L, suggestion.sizeBytes)
    }

    @Test
    fun `searchTransitCatalog omits a blank query`() = runTest {
        server.enqueue(MockResponse().setResponseCode(200).setBody("[]"))

        repository.searchTransitCatalog(
            BoundingBox(swLat = SW_LAT, swLng = SW_LNG, neLat = NE_LAT, neLng = NE_LNG),
            "   ",
        )

        assertEquals(
            "/api/transit/catalog?bbox=$SW_LAT,$SW_LNG,$NE_LAT,$NE_LNG",
            server.takeRequest().path,
        )
    }

    @Test
    fun `uploadGtfsFromUrl forwards the fallback url`() = runTest {
        server.enqueue(MockResponse().setResponseCode(201).setBody(GTFS_SOURCE_JSON))

        repository.uploadGtfsFromUrl(
            url = "https://example.test/feed.zip",
            name = "feed",
            realtimeUrl = null,
            fallbackUrl = "https://example.test/fallback.zip",
        )

        val body = server.takeRequest().body.readUtf8()
        assertTrue(body.contains("\"fallbackUrl\":\"https://example.test/fallback.zip\""))
    }

    @Test
    fun `uploadGtfsFromUrl reports realtime when the request has a realtime url`() = runTest {
        server.enqueue(MockResponse().setResponseCode(201).setBody(GTFS_SOURCE_JSON))

        val state = repository.uploadGtfsFromUrl(
            url = "https://example.test/feed.zip",
            name = "feed",
            realtimeUrl = "https://example.test/realtime.pb",
            fallbackUrl = null,
        )

        assertTrue(state.hasRealtime)
    }

    @Test
    fun `uploadGtfsFromUrl reports no realtime without a realtime url`() = runTest {
        server.enqueue(MockResponse().setResponseCode(201).setBody(GTFS_SOURCE_JSON))

        val state = repository.uploadGtfsFromUrl(
            url = "https://example.test/feed.zip",
            name = "feed",
            realtimeUrl = null,
            fallbackUrl = null,
        )

        assertFalse(state.hasRealtime)
    }

    @Test
    fun `uploadGtfsFromFile reports realtime from the request`() = runTest {
        server.enqueue(MockResponse().setResponseCode(201).setBody(GTFS_SOURCE_JSON))

        val state = repository.uploadGtfsFromFile(
            fileBytes = ByteArray(0),
            fileName = "feed.zip",
            name = "feed",
            realtimeUrl = "https://example.test/realtime.pb",
        )

        assertTrue(state.hasRealtime)
    }
}

private object FakeDeviceIdentity : DeviceIdentity {
    override val key: String = "test-device-key"
}

private const val SW_LAT = 46.5
private const val SW_LNG = 6.6
private const val NE_LAT = 46.6
private const val NE_LNG = 6.7

private const val GTFS_SOURCE_JSON = """{"uuid":"src-1","name":"feed","routes":[]}"""

private val CATALOG_JSON = """
    [{"id":"ch-1","provider":"opentransportdata.swiss","name":"Regional feed",
      "municipality":"Vaud","countryCode":"CH","downloadUrl":"https://example.test/feed.zip",
      "live":true,"scope":"regional","sizeBytes":151338107}]
""".trimIndent()
