package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
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
        repository = GameRepositoryImpl(api, api, connectionStore)
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
}
