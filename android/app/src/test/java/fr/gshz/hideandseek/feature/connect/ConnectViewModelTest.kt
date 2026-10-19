package fr.gshz.hideandseek.feature.connect

import androidx.lifecycle.SavedStateHandle
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.domain.repository.ConnectionRepository
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.every
import io.mockk.mockk
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class ConnectViewModelTest {

    private val connectionRepository = mockk<ConnectionRepository>(relaxed = true)

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(UnconfinedTestDispatcher())
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    private fun createViewModel(
        connection: ConnectionConfig? = null,
        playerName: String? = null,
        errorKey: String? = null,
        joinCode: String? = null,
        attempt: suspend (String, String) -> ProbeResult = { _, _ -> ProbeResult.Connected },
    ): ConnectViewModel {
        every { connectionRepository.observeConnection() } returns flowOf(connection)
        coEvery { connectionRepository.playerName() } returns playerName
        val savedStateHandle = SavedStateHandle(
            buildMap {
                if (errorKey != null) put(HideAndSeekDestinations.CONNECT_ERROR_KEY_ARG, errorKey)
                if (joinCode != null) put(HideAndSeekDestinations.CONNECT_JOIN_CODE_ARG, joinCode)
            },
        )
        return ConnectViewModel(connectionRepository, savedStateHandle).apply { connectAttempt = attempt }
    }

    private fun fillFields(viewModel: ConnectViewModel) {
        viewModel.onApiUrlChange("https://api.example.com/")
        viewModel.onApiKeyChange("secret")
        viewModel.onDisplayNameChange("Alice")
    }

    @Test
    fun `successful attempt saves the config and the player name and connects`() = runTest {
        val viewModel = createViewModel { _, _ -> ProbeResult.Connected }
        fillFields(viewModel)
        viewModel.connect()

        val state = viewModel.uiState.value
        assertTrue(state.connected)
        assertNull(state.error)
        assertFalse(state.isPlainHttp)
        coVerify { connectionRepository.connect(ConnectionConfig("https://api.example.com", "secret")) }
        coVerify { connectionRepository.savePlayerName("Alice") }
    }

    @Test
    fun `wrong api key surfaces an unauthorized error without saving anything`() = runTest {
        val viewModel = createViewModel { _, _ -> ProbeResult.WrongKey }
        fillFields(viewModel)
        viewModel.connect()

        val state = viewModel.uiState.value
        assertFalse(state.connected)
        assertEquals(ErrorType.Unauthorized, state.error)
        coVerify(exactly = 0) { connectionRepository.connect(any()) }
        coVerify(exactly = 0) { connectionRepository.savePlayerName(any()) }
    }

    @Test
    fun `unreachable server surfaces a network error`() = runTest {
        val viewModel = createViewModel { _, _ -> ProbeResult.Unreachable }
        fillFields(viewModel)
        viewModel.connect()

        val state = viewModel.uiState.value
        assertEquals(ErrorType.Network, state.error)
        assertFalse(state.connected)
    }

    @Test
    fun `blank fields are rejected as validation without any call`() = runTest {
        var attempted = false
        val viewModel = createViewModel { _, _ ->
            attempted = true
            ProbeResult.Connected
        }
        viewModel.onApiUrlChange("https://api.example.com")
        viewModel.onApiKeyChange("secret")
        viewModel.connect()

        val state = viewModel.uiState.value
        assertEquals(ErrorType.Validation, state.error)
        assertFalse(attempted)
        coVerify(exactly = 0) { connectionRepository.connect(any()) }
        coVerify(exactly = 0) { connectionRepository.savePlayerName(any()) }
    }

    @Test
    fun `garbage url is rejected as validation without attempting`() = runTest {
        var attempted = false
        val viewModel = createViewModel { _, _ ->
            attempted = true
            ProbeResult.Connected
        }
        viewModel.onApiUrlChange("not a url")
        viewModel.onApiKeyChange("secret")
        viewModel.onDisplayNameChange("Alice")
        viewModel.connect()

        val state = viewModel.uiState.value
        assertEquals(ErrorType.Validation, state.error)
        assertFalse(attempted)
        coVerify(exactly = 0) { connectionRepository.connect(any()) }
    }

    @Test
    fun `plain http warns but still connects`() = runTest {
        val viewModel = createViewModel { _, _ -> ProbeResult.Connected }
        viewModel.onApiUrlChange("http://10.0.2.2:8080")
        viewModel.onApiKeyChange("secret")
        viewModel.onDisplayNameChange("Alice")
        viewModel.connect()

        val state = viewModel.uiState.value
        assertTrue(state.connected)
        assertTrue(state.isPlainHttp)
    }

    @Test
    fun `https url never sets the plain-http warning`() = runTest {
        val viewModel = createViewModel { _, _ -> ProbeResult.Connected }
        fillFields(viewModel)
        viewModel.connect()

        assertFalse(viewModel.uiState.value.isPlainHttp)
    }

    @Test
    fun `qr scan with a join code navigates to join instead of home`() = runTest {
        val viewModel = createViewModel { _, _ -> ProbeResult.Connected }
        viewModel.onDisplayNameChange("Alice")
        viewModel.onQrScanned(
            """{"apiUrl":"https://api.example.com/","apiKey":"secret","joinCode":"ABC123"}""",
        )

        val state = viewModel.uiState.value
        assertFalse(state.connected)
        assertEquals("ABC123", state.scannedGameCode)
        assertEquals("https://api.example.com", state.apiUrl)
        coVerify { connectionRepository.connect(ConnectionConfig("https://api.example.com", "secret")) }
        coVerify { connectionRepository.savePlayerName("Alice") }
    }

    @Test
    fun `qr scan with a wrong key shows the unauthorized error`() = runTest {
        val viewModel = createViewModel { _, _ -> ProbeResult.WrongKey }
        viewModel.onDisplayNameChange("Alice")
        viewModel.onQrScanned("""{"apiUrl":"https://api.example.com","apiKey":"wrong"}""")

        val state = viewModel.uiState.value
        assertFalse(state.connected)
        assertEquals(ErrorType.Unauthorized, state.error)
        assertNull(state.scannedGameCode)
    }

    @Test
    fun `qr scan on an empty form fills the server and leaves the name to the user`() = runTest {
        val viewModel = createViewModel { _, _ -> ProbeResult.Connected }
        viewModel.onQrScanned(
            """{"apiUrl":"https://api.example.com","apiKey":"secret","joinCode":"ABC123"}""",
        )

        val state = viewModel.uiState.value
        assertEquals("https://api.example.com", state.apiUrl)
        assertEquals("secret", state.apiKey)
        assertEquals("ABC123", state.pendingJoinCode)
        assertNull(state.error)
        assertFalse(state.connected)
        assertNull(state.scannedGameCode)
        coVerify(exactly = 0) { connectionRepository.connect(any()) }
    }

    @Test
    fun `submitting after a qr scan keeps the scanned join code`() = runTest {
        val viewModel = createViewModel { _, _ -> ProbeResult.Connected }
        viewModel.onQrScanned(
            """{"apiUrl":"https://api.example.com","apiKey":"secret","joinCode":"ABC123"}""",
        )
        viewModel.onDisplayNameChange("Alice")
        viewModel.connect()

        val state = viewModel.uiState.value
        assertFalse(state.connected)
        assertEquals("ABC123", state.scannedGameCode)
        coVerify { connectionRepository.savePlayerName("Alice") }
    }

    @Test
    fun `stored connection and player name prefill the fields`() = runTest {
        val viewModel = createViewModel(
            attempt = { _, _ -> ProbeResult.Connected },
            connection = ConnectionConfig("https://api.example.com", "key-1"),
            playerName = "Alice",
        )

        val state = viewModel.uiState.value
        assertEquals("https://api.example.com", state.apiUrl)
        assertEquals("key-1", state.apiKey)
        assertEquals("Alice", state.displayName)
    }

    @Test
    fun `errorKey navigation argument surfaces the error`() = runTest {
        val viewModel = createViewModel(
            attempt = { _, _ -> ProbeResult.Connected },
            errorKey = "join.device_mismatch",
        )

        val state = viewModel.uiState.value
        assertEquals(ErrorType.Validation, state.error)
        assertEquals("join.device_mismatch", state.errorKey)
    }

    @Test
    fun `join code navigation argument prefills the pending game`() = runTest {
        val viewModel = createViewModel(joinCode = "ABC123")

        assertEquals("ABC123", viewModel.uiState.value.pendingJoinCode)
    }

    @Test
    fun `a display name longer than 80 characters is capped`() = runTest {
        val viewModel = createViewModel()

        viewModel.onDisplayNameChange("x".repeat(100))

        assertEquals(80, viewModel.uiState.value.displayName.length)
    }

    @Test
    fun `the plain http warning appears as soon as the url is typed`() = runTest {
        val viewModel = createViewModel()

        viewModel.onApiUrlChange("http://10.0.2.2:8080")

        assertTrue(viewModel.uiState.value.isPlainHttp)
    }

    @Test
    fun `switching the url to https clears the plain http warning`() = runTest {
        val viewModel = createViewModel()
        viewModel.onApiUrlChange("http://10.0.2.2:8080")

        viewModel.onApiUrlChange("https://api.example.com")

        assertFalse(viewModel.uiState.value.isPlainHttp)
    }

    @Test
    fun `a plain http qr scan does not auto-connect`() = runTest {
        val viewModel = createViewModel { _, _ -> ProbeResult.Connected }
        viewModel.onDisplayNameChange("Alice")

        viewModel.onQrScanned(
            """{"apiUrl":"http://10.0.2.2:8080","apiKey":"secret","joinCode":"ABC123"}""",
        )

        val state = viewModel.uiState.value
        assertTrue(state.isPlainHttp)
        assertEquals("ABC123", state.pendingJoinCode)
        coVerify(exactly = 0) { connectionRepository.connect(any()) }
    }
}
