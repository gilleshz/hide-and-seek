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
class QuestionViewModelTest {

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
    fun `a hider fetches the possible area and sees it on the map`() = runTest(testDispatcher) {
        fixture.sessionRepository.seed(
            PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
        )
        fixture.possibleAreaRepository.getPossibleAreaResult = PossibleAreaData("geo-1", null)
        val viewModel = fixture.createQuestionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.possibleAreaGeoJson == null) state = awaitItem()
            assertEquals("geo-1", state.possibleAreaGeoJson)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a seeker sees the possible area refresh promptly after an SSE possible-area event`() =
        runTest(testDispatcher) {
            seedSeeker()
            fixture.possibleAreaRepository.getPossibleAreaResult = PossibleAreaData("geo-1", null)
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                while (state.possibleAreaGeoJson == null) state = awaitItem()
                assertEquals("geo-1", state.possibleAreaGeoJson)

                fixture.possibleAreaRepository.getPossibleAreaResult = PossibleAreaData("geo-2", null)
                fixture.gameEventRepository.emitPossibleAreaEvent(PossibleAreaEvent(questionUuid = "q-1"))

                while (state.possibleAreaGeoJson != "geo-2") state = awaitItem()
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `the question sheet opens in ask mode and toggles to preview`() = runTest(testDispatcher) {
        seedSeeker()
        val viewModel = fixture.createQuestionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            viewModel.enterSimulation(QuestionCategory.Radar, emptyList())

            while (state.simulation == null) state = awaitItem()
            assertEquals(QuestionSheetMode.Ask, state.simulation?.mode)

            viewModel.updateSimulation(refreshGeometry = false) {
                it.copy(mode = QuestionSheetMode.Preview)
            }
            while (state.simulation?.mode != QuestionSheetMode.Preview) state = awaitItem()
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `asking a radar question from the sheet submits the GPS location`() = runTest(testDispatcher) {
        seedSeeker()
        fixture.locationRepository.currentLocationResult = DeviceLocation(latitude = 10.0, longitude = 20.0)
        coEvery {
            fixture.questionRepository.askRadarQuestion(any(), any(), any(), any(), any())
        } returns askedQuestion(QuestionCategory.Radar)
        val viewModel = fixture.createQuestionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            viewModel.enterSimulation(QuestionCategory.Radar, emptyList())
            while (state.simulation == null) state = awaitItem()

            viewModel.updateSimulation { it.copy(radiusMeters = 1000) }
            viewModel.askSheetQuestion()

            coVerify { fixture.questionRepository.askRadarQuestion("round-1", "player-1", 1000.0, 10.0, 20.0) }
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `asking a measuring question from the sheet submits the feature type and GPS location`() =
        runTest(testDispatcher) {
            seedSeeker()
            fixture.locationRepository.currentLocationResult = DeviceLocation(latitude = 10.0, longitude = 20.0)
            coEvery { fixture.questionRepository.askFeatureQuestion(any()) } returns
                askedQuestion(QuestionCategory.Measuring)
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                viewModel.enterSimulation(QuestionCategory.Measuring, emptyList())
                while (state.simulation?.category != QuestionCategory.Measuring) state = awaitItem()

                viewModel.updateSimulation { it.copy(featureType = "museum") }
                viewModel.askSheetQuestion()

                coVerify {
                    fixture.questionRepository.askFeatureQuestion(
                        match {
                            it.category == QuestionCategory.Measuring &&
                                it.featureType == FeatureType.Museum &&
                                it.seekerLat == 10.0 && it.seekerLng == 20.0
                        },
                    )
                }
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `a successful ask closes the sheet and surfaces the pending question for the map chip`() =
        runTest(testDispatcher) {
            seedSeeker()
            fixture.locationRepository.currentLocationResult = DeviceLocation(latitude = 10.0, longitude = 20.0)
            coEvery {
                fixture.questionRepository.askRadarQuestion(any(), any(), any(), any(), any())
            } returns askedQuestion(QuestionCategory.Radar)
            coEvery { fixture.questionRepository.listQuestions("round-1") } returns
                listOf(askedQuestion(QuestionCategory.Radar))
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                viewModel.enterSimulation(QuestionCategory.Radar, emptyList())
                while (state.simulation == null) state = awaitItem()

                viewModel.updateSimulation { it.copy(radiusMeters = 1000) }
                viewModel.askSheetQuestion()

                while (state.simulation != null || state.outstandingQuestion == null) state = awaitItem()
                assertEquals("q-1", state.outstandingQuestion?.uuid)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `a failed ask keeps the sheet open so the error stays visible`() = runTest(testDispatcher) {
        seedSeeker()
        fixture.locationRepository.currentLocationResult = DeviceLocation(latitude = 10.0, longitude = 20.0)
        coEvery {
            fixture.questionRepository.askRadarQuestion(any(), any(), any(), any(), any())
        } throws IOException("boom")
        val viewModel = fixture.createQuestionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            viewModel.enterSimulation(QuestionCategory.Radar, emptyList())
            while (state.simulation == null) state = awaitItem()

            viewModel.updateSimulation { it.copy(radiusMeters = 1000) }
            viewModel.askSheetQuestion()

            while (state.simulation?.error == null) state = awaitItem()
            assertNotNull(state.simulation)
            assertNull(state.outstandingQuestion)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `asking without a GPS fix flags the missing location permission`() = runTest(testDispatcher) {
        seedSeeker()
        fixture.locationRepository.currentLocationResult = null
        val viewModel = fixture.createQuestionViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            viewModel.enterSimulation(QuestionCategory.Radar, emptyList())
            while (state.simulation == null) state = awaitItem()

            viewModel.updateSimulation { it.copy(radiusMeters = 1000) }
            viewModel.askSheetQuestion()

            while (state.simulation?.locationPermissionMissing != true) state = awaitItem()
            coVerify(exactly = 0) {
                fixture.questionRepository.askRadarQuestion(any(), any(), any(), any(), any())
            }
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a question chat event wakes the poll so the hider sees the outstanding question`() =
        runTest(testDispatcher) {
            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
            )
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                assertNull(state.outstandingQuestion)

                coEvery { fixture.questionRepository.listQuestions("round-1") } returns
                    listOf(askedQuestion(QuestionCategory.Radar))
                fixture.gameEventRepository.emitChatEvent(chatEvent(messageType = "question"))

                while (state.outstandingQuestion == null) state = awaitItem()
                assertEquals("q-1", state.outstandingQuestion?.uuid)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `a reply chat event re-checks the poll and clears the outstanding question`() =
        runTest(testDispatcher) {
            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
            )
            coEvery { fixture.questionRepository.listQuestions("round-1") } returns
                listOf(askedQuestion(QuestionCategory.Radar))
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                fixture.gameEventRepository.emitChatEvent(chatEvent(messageType = "question"))
                while (state.outstandingQuestion == null) state = awaitItem()

                coEvery { fixture.questionRepository.listQuestions("round-1") } returns
                    listOf(
                        askedQuestion(QuestionCategory.Radar, revealedAt = "2026-01-01T00:04:00Z"),
                    )
                fixture.gameEventRepository.emitChatEvent(
                    chatEvent(messageType = "answer", replyToUuid = "msg-1"),
                )

                while (state.outstandingQuestion != null) state = awaitItem()
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `an outstanding question is discovered at startup without any chat event`() =
        runTest(testDispatcher) {
            fixture.sessionRepository.seed(
                PlayerSession("game-1", "round-1", "player-1", "Alice", "token", side = "hider"),
            )
            coEvery { fixture.questionRepository.listQuestions("round-1") } returns
                listOf(askedQuestion(QuestionCategory.Radar))
            val viewModel = fixture.createQuestionViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                while (state.outstandingQuestion == null) state = awaitItem()
                assertEquals("q-1", state.outstandingQuestion?.uuid)
                cancelAndIgnoreRemainingEvents()
            }
        }

    private fun chatEvent(messageType: String, replyToUuid: String? = null) = ChatEvent(
        uuid = "msg-${messageType.hashCode()}",
        senderUuid = "player-2",
        messageType = messageType,
        body = "Are you within 500 m of me?",
        imageRef = null,
        createdAt = "2026-01-01T00:00:00Z",
        questionUuid = "q-1",
        replyToUuid = replyToUuid,
    )

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
