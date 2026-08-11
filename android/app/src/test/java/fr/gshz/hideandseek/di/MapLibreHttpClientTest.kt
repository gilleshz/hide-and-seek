package fr.gshz.hideandseek.di

import fr.gshz.hideandseek.BuildConfig
import okhttp3.Request
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

class MapLibreHttpClientTest {

    private lateinit var server: MockWebServer

    @BeforeEach
    fun setUp() {
        server = MockWebServer()
        server.start()
    }

    @AfterEach
    fun tearDown() {
        server.shutdown()
    }

    @Test
    fun `requests carry the app user agent`() {
        server.enqueue(MockResponse().setBody("{}"))

        MapLibreHttpClient.build().newCall(Request.Builder().url(server.url("/tile")).build()).execute()
            .use { }

        val recorded = server.takeRequest()
        assertEquals("JetLag-HideSeek/${BuildConfig.VERSION_NAME}", recorded.getHeader("User-Agent"))
    }
}
