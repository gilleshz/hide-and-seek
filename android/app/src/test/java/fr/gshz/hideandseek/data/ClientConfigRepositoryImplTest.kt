package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.domain.model.SiriProfileOption
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory

class ClientConfigRepositoryImplTest {

    private lateinit var server: MockWebServer
    private lateinit var repository: ClientConfigRepositoryImpl

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
        repository = ClientConfigRepositoryImpl(retrofit.create(HideAndSeekApi::class.java))
    }

    @AfterEach
    fun tearDown() {
        server.shutdown()
    }

    @Test
    fun `maps the siri profiles`() = runTest {
        server.enqueue(
            MockResponse().setResponseCode(200).setBody(
                """{"siriProfiles":[{"key":"idfm","label":"Île-de-France Mobilités"}]}""",
            ),
        )

        val config = repository.getClientConfig(server.url("/").toString())

        assertEquals(
            listOf(SiriProfileOption("idfm", "Île-de-France Mobilités")),
            config.siriProfiles,
        )
    }

    @Test
    fun `missing siri profiles map to an empty list`() = runTest {
        server.enqueue(MockResponse().setResponseCode(200).setBody("{}"))

        val config = repository.getClientConfig(server.url("/").toString())

        assertTrue(config.siriProfiles.isEmpty())
    }
}
