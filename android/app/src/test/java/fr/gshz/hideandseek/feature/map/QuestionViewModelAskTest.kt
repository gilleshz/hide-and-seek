package fr.gshz.hideandseek.feature.map

import app.cash.turbine.test
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.DeviceLocation
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.QuestionStatus
import fr.gshz.hideandseek.domain.model.TransitLine
import io.mockk.coEvery
import io.mockk.coVerify
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNotNull
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class QuestionViewModelAskTest {

    private val fixture = MapTestFixture()
    private val testDispatcher = UnconfinedTestDispatcher()

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(testDispatcher)
        fixture.setUp()
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    private fun seedSeeker() {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "seeker"),
        )
    }

    @Test
    fun `asking a photo question submits the selected target`() = runTest(testDispatcher) {
        seedSeeker()
        coEvery { fixture.questionRepository.askPhotoQuestion(any(), any(), any()) } returns
            askedQuestion(QuestionCategory.Photos)
        val viewModel = fixture.createQuestionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            viewModel.enterSimulation(QuestionCategory.Photos, emptyList())
            while (state.simulation == null) state = awaitItem()

            viewModel.updateSimulation { it.copy(photoTarget = PhotoTarget.Tree) }
            viewModel.askSheetQuestion()

            coVerify { fixture.questionRepository.askPhotoQuestion("round-1", "player-1", PhotoTarget.Tree) }
            while (state.simulation != null) state = awaitItem()
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `selecting the transit-line option blocks asking until a line is chosen`() = runTest(testDispatcher) {
        seedSeeker()
        fixture.locationRepository.currentLocationResult = DeviceLocation(latitude = 10.0, longitude = 20.0)
        coEvery { fixture.questionRepository.askFeatureQuestion(any()) } returns
            askedQuestion(QuestionCategory.Matching)
        val viewModel = fixture.createQuestionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            viewModel.enterSimulation(QuestionCategory.Matching, listOf(transitLine()))
            while (state.simulation?.availableTransitLines.isNullOrEmpty()) state = awaitItem()
            assertEquals("M4", state.simulation?.availableTransitLines?.single()?.ref)

            viewModel.updateSimulation(refreshGeometry = false) {
                it.copy(
                    transitLineSelected = true,
                    stationNameLengthSelected = false,
                    featureType = null,
                    chosenFeatureId = null,
                    candidateFeatures = emptyList(),
                )
            }
            while (state.simulation?.transitLineSelected != true) state = awaitItem()

            viewModel.askSheetQuestion()
            coVerify(exactly = 0) { fixture.questionRepository.askFeatureQuestion(any()) }
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `asking a transit-line matching question sends the line OSM ids and no feature type`() =
        runTest(testDispatcher) {
            seedSeeker()
            fixture.locationRepository.currentLocationResult = DeviceLocation(latitude = 10.0, longitude = 20.0)
            coEvery { fixture.questionRepository.askFeatureQuestion(any()) } returns
                askedQuestion(QuestionCategory.Matching)
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                viewModel.enterSimulation(QuestionCategory.Matching, listOf(transitLine()))
                while (state.simulation?.availableTransitLines.isNullOrEmpty()) state = awaitItem()

                viewModel.updateSimulation(refreshGeometry = false) {
                    it.copy(
                        transitLineSelected = true,
                        stationNameLengthSelected = false,
                        featureType = null,
                        chosenFeatureId = null,
                        candidateFeatures = emptyList(),
                    )
                }
                viewModel.updateSimulation(refreshGeometry = false) {
                    it.copy(selectedTransitLine = state.simulation!!.availableTransitLines.single())
                }
                while (state.simulation?.selectedTransitLine == null) state = awaitItem()

                viewModel.askSheetQuestion()

                coVerify {
                    fixture.questionRepository.askFeatureQuestion(
                        match {
                            it.category == QuestionCategory.Matching &&
                                it.featureType == null &&
                                it.transitLineOsmId == "123" &&
                                it.transitLineOsmType == "relation" &&
                                it.seekerLat == 10.0 && it.seekerLng == 20.0
                        },
                    )
                }
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `selecting station name length clears feature type and transit line`() = runTest(testDispatcher) {
        seedSeeker()
        fixture.locationRepository.currentLocationResult = DeviceLocation(latitude = 10.0, longitude = 20.0)
        val viewModel = fixture.createQuestionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            viewModel.enterSimulation(QuestionCategory.Matching, listOf(transitLine()))
            while (state.simulation?.availableTransitLines.isNullOrEmpty()) state = awaitItem()

            viewModel.updateSimulation(refreshGeometry = false) {
                it.copy(
                    transitLineSelected = true,
                    stationNameLengthSelected = false,
                    featureType = null,
                    chosenFeatureId = null,
                    candidateFeatures = emptyList(),
                )
            }
            viewModel.updateSimulation(refreshGeometry = false) {
                it.copy(selectedTransitLine = state.simulation!!.availableTransitLines.single())
            }
            while (state.simulation?.selectedTransitLine == null) state = awaitItem()

            viewModel.updateSimulation(refreshGeometry = false) {
                it.copy(
                    stationNameLengthSelected = true,
                    featureType = null,
                    transitLineSelected = false,
                    selectedTransitLine = null,
                    chosenFeatureId = null,
                    candidateFeatures = emptyList(),
                )
            }
            while (!(state.simulation?.stationNameLengthSelected ?: false)) state = awaitItem()

            assertNull(state.simulation?.featureType)
            assertEquals(false, state.simulation?.transitLineSelected)
            assertNull(state.simulation?.selectedTransitLine)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `asking a station-name-length matching question sends the flag and no feature type`() =
        runTest(testDispatcher) {
            seedSeeker()
            fixture.locationRepository.currentLocationResult = DeviceLocation(latitude = 10.0, longitude = 20.0)
            coEvery { fixture.questionRepository.askFeatureQuestion(any()) } returns
                askedQuestion(QuestionCategory.Matching)
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                viewModel.enterSimulation(QuestionCategory.Matching, emptyList())
                while (state.simulation == null) state = awaitItem()

                viewModel.updateSimulation(refreshGeometry = false) {
                    it.copy(
                        stationNameLengthSelected = true,
                        featureType = null,
                        transitLineSelected = false,
                        selectedTransitLine = null,
                        chosenFeatureId = null,
                        candidateFeatures = emptyList(),
                    )
                }
                while (!(state.simulation?.stationNameLengthSelected ?: false)) state = awaitItem()

                viewModel.askSheetQuestion()

                coVerify {
                    fixture.questionRepository.askFeatureQuestion(
                        match {
                            it.category == QuestionCategory.Matching &&
                                it.stationNameLength &&
                                it.featureType == null &&
                                it.transitLineOsmId == null &&
                                it.seekerLat == 10.0 && it.seekerLng == 20.0
                        },
                    )
                }
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `selecting sea level clears the measuring feature type`() = runTest(testDispatcher) {
        seedSeeker()
        fixture.locationRepository.currentLocationResult = DeviceLocation(latitude = 10.0, longitude = 20.0)
        val viewModel = fixture.createQuestionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            viewModel.enterSimulation(QuestionCategory.Measuring, emptyList())
            while (state.simulation?.category != QuestionCategory.Measuring) state = awaitItem()

            viewModel.updateSimulation { it.copy(featureType = "museum") }
            while (state.simulation?.featureType == null) state = awaitItem()

            viewModel.updateSimulation(refreshGeometry = false) {
                it.copy(
                    seaLevelSelected = true,
                    featureType = null,
                    chosenFeatureId = null,
                    candidateFeatures = emptyList(),
                )
            }
            while (!(state.simulation?.seaLevelSelected ?: false)) state = awaitItem()

            assertNull(state.simulation?.featureType)
            assertTrue(state.simulation?.seaLevelSelected ?: false)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `asking a sea-level measuring question sends the flag, altitude, and no feature type`() =
        runTest(testDispatcher) {
            seedSeeker()
            fixture.locationRepository.currentLocationResult =
                DeviceLocation(latitude = 10.0, longitude = 20.0, altitude = 321.0)
            coEvery { fixture.questionRepository.askFeatureQuestion(any()) } returns
                askedQuestion(QuestionCategory.Measuring)
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                viewModel.enterSimulation(QuestionCategory.Measuring, emptyList())
                while (state.simulation?.category != QuestionCategory.Measuring) state = awaitItem()

                viewModel.updateSimulation(refreshGeometry = false) {
                    it.copy(
                        seaLevelSelected = true,
                        featureType = null,
                        chosenFeatureId = null,
                        candidateFeatures = emptyList(),
                    )
                }
                while (!(state.simulation?.seaLevelSelected ?: false)) state = awaitItem()

                viewModel.askSheetQuestion()

                coVerify {
                    fixture.questionRepository.askFeatureQuestion(
                        match {
                            it.category == QuestionCategory.Measuring &&
                                it.seaLevel &&
                                it.seekerAltitude == 321.0 &&
                                it.featureType == null &&
                                it.seekerLat == 10.0 && it.seekerLng == 20.0
                        },
                    )
                }
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `a photo ask without a selected target is blocked`() = runTest(testDispatcher) {
        seedSeeker()
        val viewModel = fixture.createQuestionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            viewModel.enterSimulation(QuestionCategory.Photos, emptyList())
            while (state.simulation == null) state = awaitItem()

            viewModel.askSheetQuestion()

            coVerify(exactly = 0) { fixture.questionRepository.askPhotoQuestion(any(), any(), any()) }
            assertNotNull(state.simulation)
            cancelAndIgnoreRemainingEvents()
        }
    }

    private fun askedQuestion(
        category: QuestionCategory,
        revealDeadlineAt: String? = "2026-01-01T00:05:00Z",
        revealedAt: String? = null,
        uuid: String = "q-1",
        status: QuestionStatus = QuestionStatus.Open,
    ) = AskedQuestion(
        uuid = uuid,
        roundUuid = "round-1",
        category = category,
        askedAt = "2026-01-01T00:00:00Z",
        revealDeadlineAt = revealDeadlineAt,
        revealedAt = revealedAt,
        radarAnswer = null,
        thermometerResult = null,
        status = status,
    )

    private fun transitLine() = TransitLine(
        osmId = "123",
        osmType = "relation",
        ref = "M4",
        name = "Metro 4",
        colour = "",
        routeType = "subway",
        network = "",
        operator = "",
    )
}
