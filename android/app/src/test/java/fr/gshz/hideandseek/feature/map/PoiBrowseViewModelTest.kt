package fr.gshz.hideandseek.feature.map

import app.cash.turbine.test
import fr.gshz.hideandseek.core.model.ConnectionConfig
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.data.remote.dto.FeatureDto
import fr.gshz.hideandseek.domain.model.FeatureType
import io.mockk.coEvery
import io.mockk.coVerify
import java.io.IOException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class PoiBrowseViewModelTest {

    private val fixture = MapTestFixture()
    private val testDispatcher = UnconfinedTestDispatcher()

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(testDispatcher)
        fixture.setUp()
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        coEvery { fixture.connectionStore.current() } returns
            ConnectionConfig("https://api.example.com/", "key")
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun `selecting a feature type fetches the current round's features`() = runTest(testDispatcher) {
        coEvery { fixture.questionPreviewRepository.getFeatures(any()) } returns listOf(
            FeatureDto("f1", "Louvre", 48.86, 2.33),
            FeatureDto("f2", "Orsay", 48.85, 2.32),
        )
        val viewModel = fixture.createPoiBrowseViewModel()

        viewModel.uiState.test {
            assertNull(awaitItem())
            viewModel.open()
            awaitItem()
            viewModel.selectFeatureType(FeatureType.Museum)
            var state = awaitItem()
            while (state?.isLoading != false) state = awaitItem()

            assertEquals(FeatureType.Museum, state.featureType)
            assertEquals(
                listOf(
                    FeatureSummary("f1", "Louvre", 48.86, 2.33),
                    FeatureSummary("f2", "Orsay", 48.85, 2.32),
                ),
                state.features,
            )
            coVerify {
                fixture.questionPreviewRepository.getFeatures(
                    "https://api.example.com/api/rounds/round-1/features?type=museum",
                )
            }
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a fetch failure keeps the sheet open and drops the old features`() = runTest(testDispatcher) {
        coEvery { fixture.questionPreviewRepository.getFeatures(any()) } returns
            listOf(FeatureDto("f1", "Louvre", 48.86, 2.33))
        val viewModel = fixture.createPoiBrowseViewModel()

        viewModel.uiState.test {
            awaitItem()
            viewModel.open()
            awaitItem()
            viewModel.selectFeatureType(FeatureType.Museum)
            var state = awaitItem()
            while (state?.features.isNullOrEmpty()) state = awaitItem()

            coEvery { fixture.questionPreviewRepository.getFeatures(any()) } throws IOException("offline")
            viewModel.selectFeatureType(FeatureType.Hospital)
            while (state?.error != ErrorType.Network) state = awaitItem()

            assertEquals(FeatureType.Hospital, state.featureType)
            assertTrue(state.features.isEmpty())
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `closing clears the feature type and the features`() = runTest(testDispatcher) {
        coEvery { fixture.questionPreviewRepository.getFeatures(any()) } returns
            listOf(FeatureDto("f1", "Louvre", 48.86, 2.33))
        val viewModel = fixture.createPoiBrowseViewModel()

        viewModel.uiState.test {
            assertNull(awaitItem())
            viewModel.open()
            awaitItem()
            viewModel.selectFeatureType(FeatureType.Museum)
            var state = awaitItem()
            while (state?.features.isNullOrEmpty()) state = awaitItem()
            assertEquals(FeatureType.Museum, state.featureType)

            viewModel.close()

            assertNull(awaitItem())
            cancelAndIgnoreRemainingEvents()
        }
    }
}
