package fr.gshz.hideandseek.feature.settings

import app.cash.turbine.test
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.data.SettingsStore
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.domain.model.Profile
import fr.gshz.hideandseek.domain.repository.AvatarRepository
import fr.gshz.hideandseek.fake.httpException
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.every
import io.mockk.mockk
import java.io.IOException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class SettingsViewModelTest {

    private val themeFlow = MutableStateFlow("system")
    private val playerNameFlow = MutableStateFlow<String?>(null)
    private val settingsStore = mockk<SettingsStore>(relaxed = true)
    private val connectionStore = mockk<ConnectionStore>()
    private val avatarRepository = mockk<AvatarRepository>()
    private val testDispatcher = UnconfinedTestDispatcher()

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(testDispatcher)
        every { settingsStore.locale } returns MutableStateFlow(null)
        every { settingsStore.themeMode } returns themeFlow
        every { connectionStore.playerName } returns playerNameFlow
        coEvery { connectionStore.currentPlayerName() } coAnswers { playerNameFlow.value }
        coEvery { connectionStore.savePlayerName(any()) } coAnswers { playerNameFlow.value = firstArg() }
        coEvery { avatarRepository.bootstrap(any()) } coAnswers {
            Profile("uuid-1", firstArg(), "ref-1", "https://api.test/api/avatars/ref-1")
        }
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun `initial state defaults to system theme when DataStore has no saved value`() =
        runTest(testDispatcher) {
            val viewModel = createViewModel()

            viewModel.uiState.test {
                assertEquals("system", awaitItem().selectedTheme)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `initial state reflects theme already saved in DataStore`() = runTest(testDispatcher) {
        themeFlow.value = "dark"
        val viewModel = createViewModel()

        viewModel.uiState.test {
            assertEquals("dark", awaitItem().selectedTheme)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `onThemeSelected updates state and persists to DataStore`() = runTest(testDispatcher) {
        val viewModel = createViewModel()

        viewModel.onThemeSelected("dark")

        coVerify { settingsStore.saveThemeMode("dark") }
        viewModel.uiState.test {
            assertEquals("dark", awaitItem().selectedTheme)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `onThemeSelected light updates state and persists`() = runTest(testDispatcher) {
        val viewModel = createViewModel()

        viewModel.onThemeSelected("light")

        coVerify { settingsStore.saveThemeMode("light") }
        viewModel.uiState.test {
            assertEquals("light", awaitItem().selectedTheme)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `live theme mode change from DataStore updates state`() = runTest(testDispatcher) {
        val viewModel = createViewModel()

        viewModel.uiState.test {
            assertEquals("system", awaitItem().selectedTheme)
            themeFlow.value = "dark"
            assertEquals("dark", awaitItem().selectedTheme)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `the stored name is bootstrapped and the identity is shown`() = runTest(testDispatcher) {
        playerNameFlow.value = "Alice"

        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.identityUuid == null) state = awaitItem()
            assertEquals("Alice", state.displayName)
            assertEquals("Alice", state.nameDraft)
            assertEquals("uuid-1", state.identityUuid)
            assertEquals("https://api.test/api/avatars/ref-1", state.avatarUrl)
            coVerify { avatarRepository.bootstrap("Alice") }
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a failed bootstrap keeps the stored name and shows a network error`() = runTest(testDispatcher) {
        playerNameFlow.value = "Alice"
        coEvery { avatarRepository.bootstrap(any()) } throws IOException("offline")

        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.nameError == null) state = awaitItem()
            assertEquals(ErrorType.Network, state.nameError)
            assertEquals("Alice", state.displayName)
            assertNull(state.identityUuid)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a rejected bootstrap surfaces the profile error key`() = runTest(testDispatcher) {
        playerNameFlow.value = "Alice"
        coEvery { avatarRepository.bootstrap(any()) } throws
            httpException(400, """{"errorKey":"profile.credential_invalid"}""")

        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.nameError == null) state = awaitItem()
            assertEquals(ErrorType.Validation, state.nameError)
            assertEquals("profile.credential_invalid", state.nameErrorKey)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `saving a new display name renames the identity and stores it`() = runTest(testDispatcher) {
        playerNameFlow.value = "Alice"
        coEvery { avatarRepository.rename("Bob") } returns Profile("uuid-1", "Bob", "ref-1", null)

        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.identityUuid == null) state = awaitItem()
            viewModel.onDisplayNameChange("Bob")
            viewModel.saveDisplayName()
            while (state.displayName != "Bob") state = awaitItem()
            assertEquals("Bob", state.nameDraft)
            assertEquals("Bob", playerNameFlow.value)
            coVerify { avatarRepository.rename("Bob") }
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a failed rename keeps the old name and shows the error`() = runTest(testDispatcher) {
        playerNameFlow.value = "Alice"
        coEvery { avatarRepository.rename(any()) } throws IOException("offline")

        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.identityUuid == null) state = awaitItem()
            viewModel.onDisplayNameChange("Bob")
            viewModel.saveDisplayName()
            while (state.nameError == null) state = awaitItem()
            assertEquals(ErrorType.Network, state.nameError)
            assertEquals("Alice", state.displayName)
            assertEquals("Alice", playerNameFlow.value)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a blank display name is rejected without a request`() = runTest(testDispatcher) {
        playerNameFlow.value = "Alice"

        val viewModel = createViewModel()
        viewModel.onDisplayNameChange("   ")
        viewModel.saveDisplayName()

        assertEquals(ErrorType.Validation, viewModel.uiState.value.nameError)
        coVerify(exactly = 0) { avatarRepository.rename(any()) }
    }

    @Test
    fun `removing the avatar clears the preview`() = runTest(testDispatcher) {
        playerNameFlow.value = "Alice"
        coEvery { avatarRepository.removeAvatar() } returns Unit

        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.avatarUrl == null) state = awaitItem()
            viewModel.removeAvatar()
            while (state.avatarUrl != null) state = awaitItem()
            assertNull(state.avatarUrl)
            coVerify { avatarRepository.removeAvatar() }
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a failed removal shows a network error`() = runTest(testDispatcher) {
        playerNameFlow.value = "Alice"
        coEvery { avatarRepository.removeAvatar() } throws IOException("offline")

        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.avatarUrl == null) state = awaitItem()
            viewModel.removeAvatar()
            while (state.avatarError == null) state = awaitItem()
            assertEquals(ErrorType.Network, state.avatarError)
            cancelAndIgnoreRemainingEvents()
        }
    }

    private fun createViewModel() = SettingsViewModel(
        settingsStore,
        connectionStore,
        avatarRepository,
        testDispatcher,
    )
}
