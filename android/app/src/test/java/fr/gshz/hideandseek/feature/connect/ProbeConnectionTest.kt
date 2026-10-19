package fr.gshz.hideandseek.feature.connect

import kotlinx.coroutines.test.runTest
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

class ProbeConnectionTest {

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

    private fun baseUrl() = server.url("/").toString()

    @Test
    fun `a 2xx response connects`() = runTest {
        server.enqueue(MockResponse().setResponseCode(200))

        assertEquals(ProbeResult.Connected, probeConnection(baseUrl(), "key"))
    }

    @Test
    fun `a rejected key is an unauthorized probe`() = runTest {
        server.enqueue(MockResponse().setResponseCode(401))

        assertEquals(ProbeResult.WrongKey, probeConnection(baseUrl(), "key"))
    }

    @Test
    fun `a server error is unreachable`() = runTest {
        server.enqueue(MockResponse().setResponseCode(500))

        assertEquals(ProbeResult.Unreachable, probeConnection(baseUrl(), "key"))
    }
}
