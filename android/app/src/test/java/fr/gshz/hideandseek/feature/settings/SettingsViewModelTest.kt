package fr.gshz.hideandseek.feature.settings

import app.cash.turbine.test
import fr.gshz.hideandseek.core.model.AccountCredential
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.domain.repository.ConnectionRepository
import fr.gshz.hideandseek.fake.FakeAccountRepository
import fr.gshz.hideandseek.fake.httpException
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.every
import io.mockk.mockk
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableStateFlow
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
class SettingsViewModelTest {

    private val localeFlow = MutableStateFlow<String?>(null)
    private val themeFlow = MutableStateFlow("system")
    private val settingsStore = mockk<fr.gshz.hideandseek.core.data.SettingsStore>(relaxed = true)
    private val connectionRepository = mockk<ConnectionRepository>(relaxed = true)
    private val accountRepository = FakeAccountRepository()
    private val testDispatcher = UnconfinedTestDispatcher()

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(testDispatcher)
        every { settingsStore.locale } returns localeFlow
        every { settingsStore.themeMode } returns themeFlow
        coEvery { connectionRepository.accountCredential() } returns AccountCredential("Alice", "old-password")
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun `initial state defaults to system theme when DataStore has no saved value`() =
        runTest(testDispatcher) {
            val viewModel = SettingsViewModel(settingsStore, connectionRepository, accountRepository)

            viewModel.uiState.test {
                assertEquals("system", awaitItem().selectedTheme)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `initial state reflects theme already saved in DataStore`() = runTest(testDispatcher) {
        themeFlow.value = "dark"
        val viewModel = SettingsViewModel(settingsStore, connectionRepository, accountRepository)

        viewModel.uiState.test {
            assertEquals("dark", awaitItem().selectedTheme)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `onThemeSelected updates state and persists to DataStore`() = runTest(testDispatcher) {
        val viewModel = SettingsViewModel(settingsStore, connectionRepository, accountRepository)

        viewModel.onThemeSelected("dark")

        coVerify { settingsStore.saveThemeMode("dark") }
        viewModel.uiState.test {
            assertEquals("dark", awaitItem().selectedTheme)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `onThemeSelected light updates state and persists`() = runTest(testDispatcher) {
        val viewModel = SettingsViewModel(settingsStore, connectionRepository, accountRepository)

        viewModel.onThemeSelected("light")

        coVerify { settingsStore.saveThemeMode("light") }
        viewModel.uiState.test {
            assertEquals("light", awaitItem().selectedTheme)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `live theme mode change from DataStore updates state`() = runTest(testDispatcher) {
        val viewModel = SettingsViewModel(settingsStore, connectionRepository, accountRepository)

        viewModel.uiState.test {
            assertEquals("system", awaitItem().selectedTheme)
            themeFlow.value = "dark"
            assertEquals("dark", awaitItem().selectedTheme)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `account name is loaded from the stored credential`() = runTest(testDispatcher) {
        val viewModel = SettingsViewModel(settingsStore, connectionRepository, accountRepository)

        assertEquals("Alice", viewModel.uiState.value.accountName)
    }

    @Test
    fun `changePassword success updates the stored credential and reports success`() =
        runTest(testDispatcher) {
            val viewModel = SettingsViewModel(settingsStore, connectionRepository, accountRepository)
            viewModel.onCurrentPasswordChange("old-password")
            viewModel.onNewPasswordChange("new-password")

            viewModel.changePassword()

            coVerify { connectionRepository.saveAccount(AccountCredential("Alice", "new-password")) }
            assertEquals(1, accountRepository.changePasswordCalls.size)
            assertTrue(viewModel.uiState.value.passwordChanged)
            assertEquals("", viewModel.uiState.value.currentPasswordInput)
            assertEquals("", viewModel.uiState.value.newPasswordInput)
        }

    @Test
    fun `wrong current password surfaces the server error key`() = runTest(testDispatcher) {
        accountRepository.changePasswordResult = Result.failure(
            httpException(400, """{"errorKey":"account.password_invalid"}"""),
        )
        val viewModel = SettingsViewModel(settingsStore, connectionRepository, accountRepository)
        viewModel.onCurrentPasswordChange("wrong")
        viewModel.onNewPasswordChange("new-password")

        viewModel.changePassword()

        assertEquals("account.password_invalid", viewModel.uiState.value.passwordErrorKey)
        assertEquals(ErrorType.Unknown, viewModel.uiState.value.passwordError)
        assertFalse(viewModel.uiState.value.passwordChanged)
        assertFalse(viewModel.uiState.value.isChangingPassword)
    }

    @Test
    fun `short password is rejected client-side without calling the repository`() =
        runTest(testDispatcher) {
            val viewModel = SettingsViewModel(settingsStore, connectionRepository, accountRepository)
            viewModel.onCurrentPasswordChange("old")
            viewModel.onNewPasswordChange("new-password")

            viewModel.changePassword()

            assertEquals(ErrorType.Validation, viewModel.uiState.value.passwordError)
            assertEquals(0, accountRepository.changePasswordCalls.size)
        }

    @Test
    fun `no stored credential hides the account section and makes changePassword a no-op`() =
        runTest(testDispatcher) {
            coEvery { connectionRepository.accountCredential() } returns null
            val viewModel = SettingsViewModel(settingsStore, connectionRepository, accountRepository)
            viewModel.onCurrentPasswordChange("old-password")
            viewModel.onNewPasswordChange("new-password")

            assertNull(viewModel.uiState.value.accountName)

            viewModel.changePassword()

            assertEquals(0, accountRepository.changePasswordCalls.size)
            assertFalse(viewModel.uiState.value.passwordChanged)
        }

    @Test
    fun `404 from an old server maps to the client-side server_too_old key`() =
        runTest(testDispatcher) {
            accountRepository.changePasswordResult = Result.failure(httpException(404))
            val viewModel = SettingsViewModel(settingsStore, connectionRepository, accountRepository)
            viewModel.onCurrentPasswordChange("old-password")
            viewModel.onNewPasswordChange("new-password")

            viewModel.changePassword()

            assertEquals("connect.server_too_old", viewModel.uiState.value.passwordErrorKey)
            assertFalse(viewModel.uiState.value.passwordChanged)
        }
}
