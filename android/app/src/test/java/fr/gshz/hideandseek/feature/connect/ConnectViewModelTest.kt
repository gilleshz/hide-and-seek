package fr.gshz.hideandseek.feature.connect

import androidx.lifecycle.SavedStateHandle
import fr.gshz.hideandseek.core.model.AccountCredential
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
        account: AccountCredential? = null,
        errorKey: String? = null,
        attempt: suspend (String, String, String, String) -> ConnectAttemptResult = { _, _, _, _ ->
            ConnectAttemptResult.Connected
        },
    ): ConnectViewModel {
        every { connectionRepository.observeConnection() } returns flowOf(connection)
        coEvery { connectionRepository.accountCredential() } returns account
        val savedStateHandle = SavedStateHandle(
            buildMap {
                if (errorKey != null) put(HideAndSeekDestinations.CONNECT_ERROR_KEY_ARG, errorKey)
            },
        )
        return ConnectViewModel(connectionRepository, savedStateHandle).apply { connectAttempt = attempt }
    }

    private fun fillFields(viewModel: ConnectViewModel) {
        viewModel.onApiUrlChange("https://api.example.com/")
        viewModel.onApiKeyChange("secret")
        viewModel.onDisplayNameChange("Alice")
        viewModel.onPasswordChange("hunter2")
    }

    @Test
    fun `successful attempt saves the config and the account and connects`() = runTest {
        val viewModel = createViewModel { _, _, _, _ -> ConnectAttemptResult.Connected }
        fillFields(viewModel)
        viewModel.connect()

        val state = viewModel.uiState.value
        assertTrue(state.connected)
        assertNull(state.error)
        assertFalse(state.isPlainHttp)
        coVerify { connectionRepository.connect(ConnectionConfig("https://api.example.com", "secret")) }
        coVerify { connectionRepository.saveAccount(AccountCredential("Alice", "hunter2")) }
    }

    @Test
    fun `wrong api key surfaces an unauthorized error without saving anything`() = runTest {
        val viewModel = createViewModel { _, _, _, _ -> ConnectAttemptResult.WrongKey }
        fillFields(viewModel)
        viewModel.connect()

        val state = viewModel.uiState.value
        assertFalse(state.connected)
        assertEquals(ErrorType.Unauthorized, state.error)
        coVerify(exactly = 0) { connectionRepository.connect(any()) }
        coVerify(exactly = 0) { connectionRepository.saveAccount(any()) }
    }

    @Test
    fun `wrong password surfaces the join password invalid key`() = runTest {
        val viewModel = createViewModel { _, _, _, _ -> ConnectAttemptResult.AccountError("join.password_invalid") }
        fillFields(viewModel)
        viewModel.connect()

        val state = viewModel.uiState.value
        assertFalse(state.connected)
        assertEquals(ErrorType.Unknown, state.error)
        assertEquals("join.password_invalid", state.errorKey)
        coVerify(exactly = 0) { connectionRepository.connect(any()) }
    }

    @Test
    fun `taken name surfaces the account name taken key`() = runTest {
        val viewModel = createViewModel { _, _, _, _ -> ConnectAttemptResult.AccountError("account.name_taken") }
        fillFields(viewModel)
        viewModel.connect()

        val state = viewModel.uiState.value
        assertFalse(state.connected)
        assertEquals(ErrorType.Unknown, state.error)
        assertEquals("account.name_taken", state.errorKey)
        coVerify(exactly = 0) { connectionRepository.connect(any()) }
    }

    @Test
    fun `missing accounts endpoint surfaces the server too old error`() = runTest {
        val viewModel = createViewModel { _, _, _, _ -> ConnectAttemptResult.ServerTooOld }
        fillFields(viewModel)
        viewModel.connect()

        val state = viewModel.uiState.value
        assertFalse(state.connected)
        assertEquals(ErrorType.Unknown, state.error)
        assertEquals("connect.server_too_old", state.errorKey)
        coVerify(exactly = 0) { connectionRepository.connect(any()) }
    }

    @Test
    fun `unreachable server surfaces a network error`() = runTest {
        val viewModel = createViewModel { _, _, _, _ -> ConnectAttemptResult.Unreachable }
        fillFields(viewModel)
        viewModel.connect()

        val state = viewModel.uiState.value
        assertEquals(ErrorType.Network, state.error)
        assertFalse(state.connected)
    }

    @Test
    fun `blank fields are rejected as validation without any call`() = runTest {
        var attempted = false
        val viewModel = createViewModel { _, _, _, _ ->
            attempted = true
            ConnectAttemptResult.Connected
        }
        viewModel.onApiUrlChange("https://api.example.com")
        viewModel.onApiKeyChange("secret")
        viewModel.onDisplayNameChange("Alice")
        viewModel.connect()

        val state = viewModel.uiState.value
        assertEquals(ErrorType.Validation, state.error)
        assertFalse(attempted)
        coVerify(exactly = 0) { connectionRepository.connect(any()) }
        coVerify(exactly = 0) { connectionRepository.saveAccount(any()) }
    }

    @Test
    fun `garbage url is rejected as validation without attempting`() = runTest {
        var attempted = false
        val viewModel = createViewModel { _, _, _, _ ->
            attempted = true
            ConnectAttemptResult.Connected
        }
        viewModel.onApiUrlChange("not a url")
        viewModel.onApiKeyChange("secret")
        viewModel.onDisplayNameChange("Alice")
        viewModel.onPasswordChange("hunter2")
        viewModel.connect()

        val state = viewModel.uiState.value
        assertEquals(ErrorType.Validation, state.error)
        assertFalse(attempted)
        coVerify(exactly = 0) { connectionRepository.connect(any()) }
    }

    @Test
    fun `plain http warns but still connects`() = runTest {
        val viewModel = createViewModel { _, _, _, _ -> ConnectAttemptResult.Connected }
        viewModel.onApiUrlChange("http://10.0.2.2:8080")
        viewModel.onApiKeyChange("secret")
        viewModel.onDisplayNameChange("Alice")
        viewModel.onPasswordChange("hunter2")
        viewModel.connect()

        val state = viewModel.uiState.value
        assertTrue(state.connected)
        assertTrue(state.isPlainHttp)
    }

    @Test
    fun `https url never sets the plain-http warning`() = runTest {
        val viewModel = createViewModel { _, _, _, _ -> ConnectAttemptResult.Connected }
        fillFields(viewModel)
        viewModel.connect()

        assertFalse(viewModel.uiState.value.isPlainHttp)
    }

    @Test
    fun `qr scan with a join code navigates to join instead of home`() = runTest {
        val viewModel = createViewModel { _, _, _, _ -> ConnectAttemptResult.Connected }
        viewModel.onDisplayNameChange("Alice")
        viewModel.onPasswordChange("hunter2")
        viewModel.onQrScanned(
            """{"apiUrl":"https://api.example.com/","apiKey":"secret","joinCode":"ABC123"}""",
        )

        val state = viewModel.uiState.value
        assertFalse(state.connected)
        assertEquals("ABC123", state.scannedGameCode)
        assertEquals("https://api.example.com", state.apiUrl)
        coVerify { connectionRepository.connect(ConnectionConfig("https://api.example.com", "secret")) }
        coVerify { connectionRepository.saveAccount(AccountCredential("Alice", "hunter2")) }
    }

    @Test
    fun `qr scan with a wrong key shows the unauthorized error`() = runTest {
        val viewModel = createViewModel { _, _, _, _ -> ConnectAttemptResult.WrongKey }
        viewModel.onDisplayNameChange("Alice")
        viewModel.onPasswordChange("hunter2")
        viewModel.onQrScanned("""{"apiUrl":"https://api.example.com","apiKey":"wrong"}""")

        val state = viewModel.uiState.value
        assertFalse(state.connected)
        assertEquals(ErrorType.Unauthorized, state.error)
        assertNull(state.scannedGameCode)
    }

    @Test
    fun `stored connection and account prefill the fields`() = runTest {
        val viewModel = createViewModel(
            attempt = { _, _, _, _ -> ConnectAttemptResult.Connected },
            connection = ConnectionConfig("https://api.example.com", "key-1"),
            account = AccountCredential("Alice", "hunter2"),
        )

        val state = viewModel.uiState.value
        assertEquals("https://api.example.com", state.apiUrl)
        assertEquals("key-1", state.apiKey)
        assertEquals("Alice", state.displayName)
    }

    @Test
    fun `errorKey navigation argument surfaces the error`() = runTest {
        val viewModel = createViewModel(
            attempt = { _, _, _, _ -> ConnectAttemptResult.Connected },
            errorKey = "join.password_invalid",
        )

        val state = viewModel.uiState.value
        assertEquals(ErrorType.Validation, state.error)
        assertEquals("join.password_invalid", state.errorKey)
    }
}
