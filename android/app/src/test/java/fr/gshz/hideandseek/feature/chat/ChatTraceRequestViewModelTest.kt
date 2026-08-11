package fr.gshz.hideandseek.feature.chat

import androidx.lifecycle.SavedStateHandle
import app.cash.turbine.test
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.data.GameStateCache
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.core.navigation.NavigationRequestStore
import fr.gshz.hideandseek.core.navigation.TraceRequest
import fr.gshz.hideandseek.core.notification.ChatVisibilityTracker
import fr.gshz.hideandseek.data.ImageSaver
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.repository.ChatDeletedEvent
import fr.gshz.hideandseek.domain.repository.ChatEvent
import fr.gshz.hideandseek.domain.repository.ChatReadEvent
import fr.gshz.hideandseek.domain.repository.ClientConfigRepository
import fr.gshz.hideandseek.domain.repository.GameEventRepository
import fr.gshz.hideandseek.domain.repository.ReconnectedEvent
import fr.gshz.hideandseek.domain.repository.RosterChanged
import fr.gshz.hideandseek.fake.FakeChatRepository
import fr.gshz.hideandseek.fake.FakeGameRepository
import fr.gshz.hideandseek.fake.FakeLocationRepository
import fr.gshz.hideandseek.fake.FakeQuestionRepository
import fr.gshz.hideandseek.fake.FakeSessionRepository
import io.mockk.every
import io.mockk.mockk
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableSharedFlow
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
class ChatTraceRequestViewModelTest {

    private val chatRepository = FakeChatRepository()
    private val gameRepository = FakeGameRepository()
    private val sessionRepository = FakeSessionRepository()
    private val connectionStore = mockk<ConnectionStore>(relaxed = true)
    private val gameEventRepository = mockk<GameEventRepository>(relaxed = true)
    private val questionRepository = FakeQuestionRepository()
    private val gameStateCache = GameStateCache(gameRepository, mockk<ClientConfigRepository>(relaxed = true))
    private val navigationRequestStore = NavigationRequestStore()
    private val testDispatcher = UnconfinedTestDispatcher()

    private val chatEventFlow = MutableSharedFlow<ChatEvent>(extraBufferCapacity = 8)
    private val chatReadEventFlow = MutableSharedFlow<ChatReadEvent>(extraBufferCapacity = 8)
    private val chatDeletedEventFlow = MutableSharedFlow<ChatDeletedEvent>(extraBufferCapacity = 8)
    private val rosterEventFlow = MutableSharedFlow<RosterChanged>(extraBufferCapacity = 8)
    private val reconnectedEventFlow = MutableSharedFlow<ReconnectedEvent>(extraBufferCapacity = 8)

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(testDispatcher)
        every { gameEventRepository.chatEvents } returns chatEventFlow
        every { gameEventRepository.chatReadEvents } returns chatReadEventFlow
        every { gameEventRepository.chatDeletedEvents } returns chatDeletedEventFlow
        every { gameEventRepository.rosterEvents } returns rosterEventFlow
        every { gameEventRepository.reconnectedEvents } returns reconnectedEventFlow
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", "hider"))
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    private fun createViewModel(): ChatViewModel {
        val vm = ChatViewModel(
            chatRepository,
            sessionRepository,
            connectionStore,
            gameEventRepository,
            questionRepository,
            FakeLocationRepository(),
            ChatVisibilityTracker(),
            gameStateCache,
            mockk<ImageSaver>(),
            navigationRequestStore,
            SavedStateHandle(mapOf(HideAndSeekDestinations.CHAT_ARG to "game-1")),
        )
        testDispatcher.scheduler.advanceUntilIdle()
        return vm
    }

    private fun photoQuestion(target: PhotoTarget) = AskedQuestion(
        uuid = "q1",
        roundUuid = "round-1",
        category = QuestionCategory.Photos,
        askedAt = "2026-07-05T12:00:00Z",
        revealDeadlineAt = "2026-07-05T12:05:00Z",
        revealedAt = null,
        radarAnswer = null,
        thermometerResult = null,
        photoTarget = target,
    )

    private suspend fun requestTraceOnceLoaded(target: PhotoTarget, questionUuid: String): TraceRequest? {
        questionRepository.questions += photoQuestion(target)
        val viewModel = createViewModel()
        viewModel.uiState.test {
            var state = awaitItem()
            while (state.questionsByUuid["q1"] == null) state = awaitItem()
            viewModel.requestTrace(questionUuid)
            cancelAndIgnoreRemainingEvents()
        }
        return navigationRequestStore.pendingTraceRequest.value
    }

    @Test
    fun `the streets-traced question is parked for the map with this chat's game`() = runTest(testDispatcher) {
        val parked = requestTraceOnceLoaded(PhotoTarget.StreetsTraced, "q1")

        assertEquals("game-1", parked?.gameUuid)
        assertEquals(TraceRequest("game-1", "q1", PhotoTarget.StreetsTraced), parked)
    }

    @Test
    fun `the nearest-street question is parked with its own target`() = runTest(testDispatcher) {
        assertEquals(
            TraceRequest("game-1", "q1", PhotoTarget.TraceNearestStreet),
            requestTraceOnceLoaded(PhotoTarget.TraceNearestStreet, "q1"),
        )
    }

    @Test
    fun `a non-traceable photo question parks nothing`() = runTest(testDispatcher) {
        assertNull(requestTraceOnceLoaded(PhotoTarget.Tree, "q1"))
    }

    @Test
    fun `an unknown question parks nothing`() = runTest(testDispatcher) {
        assertNull(requestTraceOnceLoaded(PhotoTarget.StreetsTraced, "unknown-q"))
    }
}
