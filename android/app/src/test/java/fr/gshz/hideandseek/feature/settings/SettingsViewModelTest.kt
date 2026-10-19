package fr.gshz.hideandseek.feature.settings

import app.cash.turbine.test
import fr.gshz.hideandseek.core.data.SettingsStore
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
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class SettingsViewModelTest {

    private val themeFlow = MutableStateFlow("system")
    private val settingsStore = mockk<SettingsStore>(relaxed = true)
    private val testDispatcher = UnconfinedTestDispatcher()

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(testDispatcher)
        every { settingsStore.locale } returns MutableStateFlow(null)
        every { settingsStore.themeMode } returns themeFlow
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun `initial state defaults to system theme when DataStore has no saved value`() =
        runTest(testDispatcher) {
            val viewModel = SettingsViewModel(settingsStore)

            viewModel.uiState.test {
                assertEquals("system", awaitItem().selectedTheme)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `initial state reflects theme already saved in DataStore`() = runTest(testDispatcher) {
        themeFlow.value = "dark"
        val viewModel = SettingsViewModel(settingsStore)

        viewModel.uiState.test {
            assertEquals("dark", awaitItem().selectedTheme)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `onThemeSelected updates state and persists to DataStore`() = runTest(testDispatcher) {
        val viewModel = SettingsViewModel(settingsStore)

        viewModel.onThemeSelected("dark")

        coVerify { settingsStore.saveThemeMode("dark") }
        viewModel.uiState.test {
            assertEquals("dark", awaitItem().selectedTheme)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `onThemeSelected light updates state and persists`() = runTest(testDispatcher) {
        val viewModel = SettingsViewModel(settingsStore)

        viewModel.onThemeSelected("light")

        coVerify { settingsStore.saveThemeMode("light") }
        viewModel.uiState.test {
            assertEquals("light", awaitItem().selectedTheme)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `live theme mode change from DataStore updates state`() = runTest(testDispatcher) {
        val viewModel = SettingsViewModel(settingsStore)

        viewModel.uiState.test {
            assertEquals("system", awaitItem().selectedTheme)
            themeFlow.value = "dark"
            assertEquals("dark", awaitItem().selectedTheme)
            cancelAndIgnoreRemainingEvents()
        }
    }
}
