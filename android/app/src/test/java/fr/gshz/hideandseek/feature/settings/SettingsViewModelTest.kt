package fr.gshz.hideandseek.feature.settings

import app.cash.turbine.test
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.data.SettingsStore
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.domain.model.Profile
import fr.gshz.hideandseek.domain.repository.AvatarRepository
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
    fun `the stored profile fills the avatar preview`() = runTest(testDispatcher) {
        playerNameFlow.value = "Alice"
        coEvery { avatarRepository.getProfile() } returns
            Profile("Alice", "ref-1", "https://api.test/api/avatars/ref-1")

        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.avatarUrl == null) state = awaitItem()
            assertEquals("https://api.test/api/avatars/ref-1", state.avatarUrl)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `removing the avatar clears the preview`() = runTest(testDispatcher) {
        playerNameFlow.value = "Alice"
        coEvery { avatarRepository.getProfile() } returns
            Profile("Alice", "ref-1", "https://api.test/api/avatars/ref-1")
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
        coEvery { avatarRepository.getProfile() } returns
            Profile("Alice", "ref-1", "https://api.test/api/avatars/ref-1")
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
