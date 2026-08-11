package fr.gshz.hideandseek.feature.map

import app.cash.turbine.test
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.DeviceLocation
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.FeatureType
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.QuestionStatus
import fr.gshz.hideandseek.domain.model.TransitLine
import fr.gshz.hideandseek.domain.repository.ChatEvent
import fr.gshz.hideandseek.domain.repository.PossibleAreaData
import fr.gshz.hideandseek.domain.repository.PossibleAreaEvent
import fr.gshz.hideandseek.fake.httpException
import io.mockk.coEvery
import io.mockk.coVerify
import java.io.IOException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.advanceTimeBy
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
class QuestionViewModelSimulationTest {

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
    fun `the thermometer two-step flow starts a real ask and completes with the arrival GPS fix`() =
        runTest(testDispatcher) {
            seedSeeker()
            fixture.locationRepository.currentLocationResult = DeviceLocation(latitude = 10.0, longitude = 20.0)
            val traveling = askedQuestion(QuestionCategory.Thermometer, revealDeadlineAt = null)
            coEvery { fixture.questionRepository.askThermometerQuestion(any()) } returns traveling
            coEvery { fixture.questionRepository.completeThermometer(any(), any(), any(), any()) } returns
                askedQuestion(QuestionCategory.Thermometer)
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                viewModel.enterSimulation(QuestionCategory.Thermometer, emptyList())
                while (state.simulation == null) state = awaitItem()

                viewModel.updateSimulation { it.copy(distanceMeters = 1000) }
                coEvery { fixture.questionRepository.listQuestions("round-1") } returns listOf(traveling)
                viewModel.startThermometer()
                while (state.outstandingQuestion == null) state = awaitItem()

                coVerify {
                    fixture.questionRepository.askThermometerQuestion(
                        match {
                            it.startLat == 10.0 && it.startLng == 20.0 &&
                                it.distanceMeters == 1000.0
                        },
                    )
                }

                fixture.locationRepository.currentLocationResult = DeviceLocation(latitude = 11.0, longitude = 21.0)
                viewModel.confirmThermometerArrival()

                coVerify { fixture.questionRepository.completeThermometer("q-1", "player-1", 11.0, 21.0) }
                while (state.simulation != null) state = awaitItem()
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `the refetch the ask triggers clears the start button without waiting for a poll cycle`() =
        runTest(testDispatcher) {
            seedSeeker()
            fixture.locationRepository.currentLocationResult = DeviceLocation(latitude = 10.0, longitude = 20.0)
            val traveling = askedQuestion(QuestionCategory.Thermometer, revealDeadlineAt = null)
            var asked = false
            coEvery { fixture.questionRepository.askThermometerQuestion(any()) } answers {
                asked = true
                traveling
            }
            coEvery { fixture.questionRepository.listQuestions("round-1") } answers {
                if (asked) listOf(traveling) else emptyList()
            }
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                viewModel.enterSimulation(QuestionCategory.Thermometer, emptyList())
                while (state.simulation == null) state = awaitItem()
                assertNull(state.outstandingQuestion)

                viewModel.updateSimulation { it.copy(distanceMeters = 1000) }
                viewModel.startThermometer()

                while (state.outstandingQuestion == null) state = awaitItem()
                while (state.simulation?.isSubmitting != false) state = awaitItem()
                assertNotNull(state.outstandingQuestion)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `the start button re-enables after a timeout when the poll never confirms the thermometer`() =
        runTest(testDispatcher) {
            seedSeeker()
            fixture.locationRepository.currentLocationResult = DeviceLocation(latitude = 10.0, longitude = 20.0)
            coEvery { fixture.questionRepository.askThermometerQuestion(any()) } returns
                askedQuestion(QuestionCategory.Thermometer, revealDeadlineAt = null)
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                viewModel.enterSimulation(QuestionCategory.Thermometer, emptyList())
                while (state.simulation == null) state = awaitItem()

                viewModel.updateSimulation { it.copy(distanceMeters = 1000) }
                viewModel.startThermometer()
                while (state.simulation?.isSubmitting != true) state = awaitItem()

                advanceTimeBy(35_001)
                while (state.simulation?.isSubmitting != false) state = awaitItem()
                assertNull(state.simulation?.error)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `a traveling thermometer from the server poll shows the confirm step even without local state`() =
        runTest(testDispatcher) {
            seedSeeker()
            coEvery { fixture.questionRepository.listQuestions("round-1") } returns
                listOf(askedQuestion(QuestionCategory.Thermometer, revealDeadlineAt = null))
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                viewModel.enterSimulation(QuestionCategory.Thermometer, emptyList())
                while (state.outstandingQuestion == null) state = awaitItem()
                assertEquals("q-1", state.outstandingQuestion?.uuid)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `reopening the sheet while a thermometer travels lands on the thermometer step`() =
        runTest(testDispatcher) {
            seedSeeker()
            coEvery { fixture.questionRepository.listQuestions("round-1") } returns
                listOf(askedQuestion(QuestionCategory.Thermometer, revealDeadlineAt = null))
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                viewModel.enterSimulation(QuestionCategory.Radar, emptyList())
                while (state.outstandingQuestion == null) state = awaitItem()

                viewModel.exitSimulation()
                while (state.simulation != null) state = awaitItem()

                viewModel.enterSimulation(QuestionCategory.Radar, emptyList())
                while (state.simulation == null) state = awaitItem()
                assertEquals(QuestionCategory.Thermometer, state.simulation?.category)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `a rejected thermometer completion keeps the sheet open with a visible error`() =
        runTest(testDispatcher) {
            seedSeeker()
            fixture.locationRepository.currentLocationResult = DeviceLocation(latitude = 10.0, longitude = 20.0)
            coEvery { fixture.questionRepository.listQuestions("round-1") } returns
                listOf(askedQuestion(QuestionCategory.Thermometer, revealDeadlineAt = null))
            coEvery { fixture.questionRepository.completeThermometer(any(), any(), any(), any()) } throws
                httpException(422)
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                viewModel.enterSimulation(QuestionCategory.Thermometer, emptyList())
                while (state.outstandingQuestion == null) state = awaitItem()

                viewModel.confirmThermometerArrival()

                while (state.simulation?.error == null) state = awaitItem()
                assertNotNull(state.simulation)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `switching category keeps preview mode instead of silently reverting to ask`() = runTest(testDispatcher) {
        seedSeeker()
        val viewModel = fixture.createQuestionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            viewModel.enterSimulation(QuestionCategory.Radar, emptyList())
            togglePreviewMode(viewModel)
            while (state.simulation?.mode != QuestionSheetMode.Preview) state = awaitItem()

            viewModel.setSimCategory(QuestionCategory.Thermometer, emptyList())
            while (state.simulation?.category != QuestionCategory.Thermometer) state = awaitItem()
            assertEquals(QuestionSheetMode.Preview, state.simulation?.mode)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `an outstanding question from the poll is surfaced in the sheet state`() = runTest(testDispatcher) {
        seedSeeker()
        coEvery { fixture.questionRepository.listQuestions("round-1") } returns
            listOf(askedQuestion(QuestionCategory.Radar))
        val viewModel = fixture.createQuestionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            viewModel.enterSimulation(QuestionCategory.Measuring, emptyList())
            while (state.outstandingQuestion == null) state = awaitItem()
            assertEquals("q-1", state.outstandingQuestion?.uuid)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a randomized question replaced by a revealed one leaves no outstanding question`() =
        runTest(testDispatcher) {
            seedSeeker()
            coEvery { fixture.questionRepository.listQuestions("round-1") } returns listOf(
                askedQuestion(QuestionCategory.Matching, uuid = "q-1", status = QuestionStatus.Randomized),
                askedQuestion(QuestionCategory.Matching, uuid = "q-2", revealedAt = "2026-01-01T00:03:00Z"),
            )
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                viewModel.enterSimulation(QuestionCategory.Matching, emptyList())
                while (state.askedQuestions.isEmpty()) state = awaitItem()
                assertNull(state.outstandingQuestion)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `cancelling the outstanding question calls the repository with its uuid`() = runTest(testDispatcher) {
        seedSeeker()
        coEvery { fixture.questionRepository.listQuestions("round-1") } returns
            listOf(askedQuestion(QuestionCategory.Radar))
        coEvery { fixture.questionRepository.cancelQuestion(any(), any()) } returns Unit
        val viewModel = fixture.createQuestionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            viewModel.enterSimulation(QuestionCategory.Radar, emptyList())
            while (state.outstandingQuestion == null) state = awaitItem()

            viewModel.cancelQuestion()

            coVerify { fixture.questionRepository.cancelQuestion("q-1", "player-1") }
            cancelAndIgnoreRemainingEvents()
        }
    }

    private fun togglePreviewMode(viewModel: QuestionViewModel) {
        viewModel.updateSimulation(refreshGeometry = false) {
            it.copy(
                mode = if (it.mode == QuestionSheetMode.Ask) QuestionSheetMode.Preview
                    else QuestionSheetMode.Ask,
            )
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
}
