package fr.gshz.hideandseek.feature.chat

import androidx.lifecycle.SavedStateHandle
import app.cash.turbine.test
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.data.GameStateCache
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.core.navigation.NavigationRequestStore
import fr.gshz.hideandseek.core.notification.ChatVisibilityTracker
import fr.gshz.hideandseek.data.ImageSaver
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.ChatMessage
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.Side
import fr.gshz.hideandseek.domain.repository.ChatEvent
import fr.gshz.hideandseek.domain.repository.ChatDeletedEvent
import fr.gshz.hideandseek.domain.repository.ChatReadEvent
import fr.gshz.hideandseek.domain.repository.GameEventRepository
import fr.gshz.hideandseek.domain.repository.ReconnectedEvent
import fr.gshz.hideandseek.domain.repository.RosterChanged
import fr.gshz.hideandseek.fake.FakeChatRepository
import fr.gshz.hideandseek.fake.FakeGameRepository
import fr.gshz.hideandseek.fake.FakeLocationRepository
import fr.gshz.hideandseek.fake.FakeQuestionRepository
import fr.gshz.hideandseek.fake.FakeSessionRepository
import fr.gshz.hideandseek.fake.httpException
import io.mockk.coEvery
import io.mockk.every
import io.mockk.mockk
import java.io.IOException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
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
class ChatViewModelTest {

    private val chatRepository = FakeChatRepository()
    private val gameRepository = FakeGameRepository()
    private val sessionRepository = FakeSessionRepository()
    private val connectionStore = mockk<ConnectionStore>(relaxed = true)
    private val gameEventRepository = mockk<GameEventRepository>(relaxed = true)
    private val questionRepository = FakeQuestionRepository()
    private val locationRepository = FakeLocationRepository()
    private val chatVisibilityTracker = ChatVisibilityTracker()
    private val clientConfigRepository =
        mockk<fr.gshz.hideandseek.domain.repository.ClientConfigRepository>(relaxed = true)
    private val gameStateCache = GameStateCache(gameRepository, clientConfigRepository)
    private val imageSaver = mockk<ImageSaver>()
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
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    private fun createViewModel(gameUuid: String = "game-1"): ChatViewModel {
        val vm = ChatViewModel(
            chatRepository,
            sessionRepository,
            connectionStore,
            gameEventRepository,
            questionRepository,
            locationRepository,
            chatVisibilityTracker,
            gameStateCache,
            imageSaver,
            navigationRequestStore,
            SavedStateHandle(mapOf(HideAndSeekDestinations.CHAT_ARG to gameUuid)),
        )
        testDispatcher.scheduler.advanceUntilIdle()
        return vm
    }

    private fun hiderSession() = PlayerSession("game-1", "round-1", "p1", "Alice", "token", "hider")

    private fun questionMessage(uuid: String = "m-q1", questionUuid: String = "q1") = ChatMessage(
        uuid = uuid,
        senderUuid = "p2",
        type = "question",
        body = "Are you within 500 m of me?",
        imageRef = null,
        createdAt = "2026-07-05T12:00:00Z",
        questionUuid = questionUuid,
    )

    private fun radarQuestion(uuid: String = "q1") = AskedQuestion(
        uuid = uuid,
        roundUuid = "round-1",
        category = QuestionCategory.Radar,
        askedAt = "2026-07-05T12:00:00Z",
        revealDeadlineAt = "2026-07-05T12:05:00Z",
        revealedAt = null,
        radarAnswer = null,
        thermometerResult = null,
        radiusMeters = 500.0,
        seekerLat = 52.52,
        seekerLng = 13.405,
    )

    @Test
    fun `messages appear in chronological order`() = runTest(testDispatcher) {
        chatRepository.messages += listOf(
            ChatMessage(
                uuid = "1", senderUuid = "p1", type = "text", body = "first",
                imageRef = null, createdAt = "2026-07-05T12:00:00Z",
            ),
            ChatMessage(
                uuid = "2", senderUuid = "p2", type = "text", body = "second",
                imageRef = null, createdAt = "2026-07-05T12:01:00Z",
            ),
            ChatMessage(
                uuid = "3", senderUuid = "p1", type = "text", body = "third",
                imageRef = null, createdAt = "2026-07-05T12:02:00Z",
            ),
        )
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.messages.size < 3) state = awaitItem()
            assertEquals(3, state.messages.size)
            assertEquals("first", state.messages[0].body)
            assertEquals("second", state.messages[1].body)
            assertEquals("third", state.messages[2].body)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `own messages are aligned right and show sender as self`() = runTest(testDispatcher) {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", null))
        chatRepository.messages += listOf(
            ChatMessage(
                uuid = "1", senderUuid = "p1", type = "text", body = "from me",
                imageRef = null, createdAt = "2026-07-05T12:00:00Z",
            ),
            ChatMessage(
                uuid = "2", senderUuid = "p2", type = "text", body = "from other",
                imageRef = null, createdAt = "2026-07-05T12:01:00Z",
            ),
        )
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.messages.size < 2) state = awaitItem()
            assertEquals("p1", state.selfPlayerUuid)
            assertTrue(state.messages.first().senderUuid == state.selfPlayerUuid)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `system messages have null senderUuid`() = runTest(testDispatcher) {
        chatRepository.messages += ChatMessage(
            uuid = "s-1",
            senderUuid = null,
            type = "system",
            body = "Round ended",
            imageRef = null,
            createdAt = "2026-07-05T12:00:00Z",
        )
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.messages.isEmpty()) state = awaitItem()
            val systemMsg = state.messages.first()
            assertEquals("system", systemMsg.type)
            assertTrue(systemMsg.isSystem)
            assertEquals("Round ended", systemMsg.body)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `sending a message adds it to the list`() = runTest(testDispatcher) {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", null))
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            assertEquals(0, state.messages.size)

            viewModel.sendMessage("Hello world")

            while (state.messages.isEmpty()) state = awaitItem()
            assertEquals(1, state.messages.size)
            assertEquals("Hello world", state.messages.first().body)
            assertEquals("msg-1", state.messages.first().uuid)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `blank messages are not sent`() = runTest(testDispatcher) {
        val viewModel = createViewModel()

        viewModel.sendMessage("")
        viewModel.sendMessage("   ")

        assertEquals(0, chatRepository.postedMessages.size)
    }

    @Test
    fun `pending question message is pending for the hider`() = runTest(testDispatcher) {
        sessionRepository.seed(hiderSession())
        chatRepository.messages += questionMessage()
        questionRepository.questions += radarQuestion()
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.messages.isEmpty() || state.questionsByUuid.isEmpty()) state = awaitItem()
            assertEquals(Side.Hider, state.side)
            assertTrue(state.isPendingQuestion(state.messages.first()))
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `question join populates deadline and coordinates`() = runTest(testDispatcher) {
        sessionRepository.seed(hiderSession())
        chatRepository.messages += questionMessage()
        questionRepository.questions += radarQuestion()
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.questionsByUuid.isEmpty()) state = awaitItem()
            val joined = state.joinedQuestion(state.messages.first())
            assertNotNull(joined)
            assertEquals("2026-07-05T12:05:00Z", joined?.revealDeadlineAt)
            assertEquals(52.52, joined?.seekerLat)
            assertEquals(13.405, joined?.seekerLng)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `an answer landing in chat costs exactly one question refetch`() = runTest(testDispatcher) {
        sessionRepository.seed(hiderSession())
        questionRepository.questions += radarQuestion()
        createViewModel()
        val callsAfterLoad = questionRepository.listQuestionsCalls

        chatEventFlow.emit(
            ChatEvent(
                uuid = "m-a1",
                senderUuid = "p1",
                messageType = "answer",
                body = "No, not within range",
                imageRef = null,
                createdAt = "2026-07-05T12:01:00Z",
                questionUuid = "q1",
                replyToUuid = "m-q1",
            ),
        )
        testDispatcher.scheduler.advanceUntilIdle()

        assertEquals(callsAfterLoad + 1, questionRepository.listQuestionsCalls)
    }

    @Test
    fun `answer reply clears pending state`() = runTest(testDispatcher) {
        sessionRepository.seed(hiderSession())
        chatRepository.messages += questionMessage()
        chatRepository.messages += ChatMessage(
            uuid = "m-a1",
            senderUuid = "p1",
            type = "answer",
            body = "No, not within range",
            imageRef = null,
            createdAt = "2026-07-05T12:01:00Z",
            questionUuid = "q1",
            replyToUuid = "m-q1",
        )
        questionRepository.questions += radarQuestion().copy(revealedAt = "2026-07-05T12:01:00Z")
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.messages.size < 2) state = awaitItem()
            assertFalse(state.isPendingQuestion(state.messages.first()))
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `cancel notice reply clears pending state`() = runTest(testDispatcher) {
        sessionRepository.seed(hiderSession())
        chatRepository.messages += questionMessage()
        chatRepository.messages += ChatMessage(
            uuid = "m-c1",
            senderUuid = null,
            type = "system",
            body = "Question cancelled.",
            imageRef = null,
            createdAt = "2026-07-05T12:01:00Z",
            questionUuid = "q1",
            replyToUuid = "m-q1",
        )
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.messages.size < 2) state = awaitItem()
            assertFalse(state.isPendingQuestion(state.messages.first()))
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `reveal happy path updates question and clears isRevealing`() = runTest(testDispatcher) {
        sessionRepository.seed(hiderSession())
        chatRepository.messages += questionMessage()
        questionRepository.questions += radarQuestion()
        val viewModel = createViewModel()

        viewModel.revealQuestion("q1")
        advanceUntilIdle()

        assertEquals(listOf("q1"), questionRepository.revealedCalls)
        viewModel.uiState.test {
            var state = awaitItem()
            while (state.questionsByUuid["q1"]?.revealedAt == null) state = awaitItem()
            assertFalse(state.isRevealing)
            assertFalse(state.isPendingQuestion(state.messages.first()))
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a transit-line matching question is pending and reveals via the generic reveal path`() =
        runTest(testDispatcher) {
            sessionRepository.seed(hiderSession())
            chatRepository.messages += questionMessage()
            questionRepository.questions += transitMatchingQuestion()
            val viewModel = createViewModel()

            viewModel.uiState.test {
                var state = awaitItem()
                while (state.questionsByUuid["q1"] == null) state = awaitItem()
                assertTrue(state.isPendingQuestion(state.messages.first { it.questionUuid == "q1" }))
                cancelAndIgnoreRemainingEvents()
            }

            viewModel.revealQuestion("q1")
            advanceUntilIdle()
            assertEquals(listOf("q1"), questionRepository.revealedCalls)
        }

    private fun transitMatchingQuestion(uuid: String = "q1") = AskedQuestion(
        uuid = uuid,
        roundUuid = "round-1",
        category = QuestionCategory.Matching,
        askedAt = "2026-07-05T12:00:00Z",
        revealDeadlineAt = "2026-07-05T12:05:00Z",
        revealedAt = null,
        radarAnswer = null,
        thermometerResult = null,
        transitLineLabel = "M4: Porte de Clignancourt",
    )

    @Test
    fun `screen resume and pause update the visibility tracker`() = runTest(testDispatcher) {
        val viewModel = createViewModel()

        viewModel.onScreenResumed()
        assertEquals("game-1", chatVisibilityTracker.visibleChatGameUuid.value)

        viewModel.onScreenPaused()
        assertEquals(null, chatVisibilityTracker.visibleChatGameUuid.value)
    }

    @Test
    fun `losing the reveal race surfaces the reason a teammate or the deadline got there first`() =
        runTest(testDispatcher) {
            sessionRepository.seed(hiderSession())
            chatRepository.messages += questionMessage()
            questionRepository.questions += radarQuestion()
            val viewModel = createViewModel()

            questionRepository.revealResult = Result.failure(
                httpException(409, """{"errorKey":"question.already_revealed"}"""),
            )
            viewModel.revealQuestion("q1")
            advanceUntilIdle()

            viewModel.uiState.test {
                var state = awaitItem()
                while (state.revealErrorKey == null) state = awaitItem()
                assertEquals("question.already_revealed", state.revealErrorKey)
                assertFalse(state.isRevealing)
                assertFalse(state.revealError)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `reveal http failure refreshes questions and clears isRevealing`() = runTest(testDispatcher) {
        sessionRepository.seed(hiderSession())
        chatRepository.messages += questionMessage()
        questionRepository.questions += radarQuestion()
        val viewModel = createViewModel()

        questionRepository.revealResult = Result.failure(httpException(409))
        questionRepository.questions = mutableListOf(radarQuestion().copy(revealedAt = "2026-07-05T12:05:00Z"))
        viewModel.revealQuestion("q1")
        advanceUntilIdle()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.questionsByUuid["q1"]?.revealedAt == null) state = awaitItem()
            assertFalse(state.isRevealing)
            assertFalse(state.revealError)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `randomize http rejection surfaces the server error key instead of clearing silently`() =
        runTest(testDispatcher) {
            sessionRepository.seed(hiderSession())
            chatRepository.messages += questionMessage()
            questionRepository.questions += radarQuestion()
            val viewModel = createViewModel()

            questionRepository.randomizeResult =
                Result.failure(httpException(409, """{"errorKey":"question.all_questions_asked"}"""))
            viewModel.randomizeQuestion("q1", "content://card.jpg")
            advanceUntilIdle()

            viewModel.uiState.test {
                var state = awaitItem()
                while (state.powerupErrorKey == null) state = awaitItem()
                assertEquals("question.all_questions_asked", state.powerupErrorKey)
                assertFalse(state.isPlayingPowerup)
                assertFalse(state.powerupError)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `traveling thermometer info bubble is a pending powerup target for the hider`() {
        val infoMessage = ChatMessage(
            uuid = "m-t1",
            senderUuid = "p2",
            type = "question_info",
            body = "Started a thermometer",
            imageRef = null,
            createdAt = "2026-07-05T12:00:00Z",
            questionUuid = "t1",
        )
        val traveling = AskedQuestion(
            uuid = "t1",
            roundUuid = "round-1",
            category = QuestionCategory.Thermometer,
            askedAt = "2026-07-05T12:00:00Z",
            revealDeadlineAt = null,
            revealedAt = null,
            radarAnswer = null,
            thermometerResult = null,
        )
        val state = ChatUiState(
            messages = listOf(infoMessage),
            questionsByUuid = mapOf("t1" to traveling),
            side = Side.Hider,
        )

        assertTrue(state.isTravelingThermometer("t1"))
        assertTrue(state.isPendingTravelingThermometer(infoMessage))

        val completed = state.copy(
            questionsByUuid = mapOf("t1" to traveling.copy(revealDeadlineAt = "2026-07-05T12:05:00Z")),
        )
        assertFalse(completed.isTravelingThermometer("t1"))
        assertFalse(completed.isPendingTravelingThermometer(infoMessage))
    }

    @Test
    fun `download image success surfaces saved outcome and dismiss clears it`() = runTest(testDispatcher) {
        coEvery { imageSaver.save("https://api/img/1") } returns Result.success(Unit)
        val viewModel = createViewModel()

        viewModel.downloadImage("https://api/img/1")
        advanceUntilIdle()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.downloadOutcome == null) state = awaitItem()
            assertEquals(DownloadOutcome.Saved, state.downloadOutcome)

            viewModel.dismissDownloadOutcome()
            while (state.downloadOutcome != null) state = awaitItem()
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `download image failure surfaces failed outcome`() = runTest(testDispatcher) {
        coEvery { imageSaver.save("https://api/img/1") } returns Result.failure(IOException("boom"))
        val viewModel = createViewModel()

        viewModel.downloadImage("https://api/img/1")
        advanceUntilIdle()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.downloadOutcome == null) state = awaitItem()
            assertEquals(DownloadOutcome.Failed, state.downloadOutcome)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `download permission denied surfaces failed outcome`() = runTest(testDispatcher) {
        val viewModel = createViewModel()

        viewModel.onDownloadPermissionDenied()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.downloadOutcome == null) state = awaitItem()
            assertEquals(DownloadOutcome.Failed, state.downloadOutcome)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `sendMessage with replyToUuid passes it to the repository`() = runTest(testDispatcher) {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", null))
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            assertEquals(0, state.messages.size)

            viewModel.sendMessage("Hello world", "target-uuid-1")

            while (state.messages.isEmpty()) state = awaitItem()
            assertEquals(1, state.messages.size)
            assertEquals("Hello world", state.messages.first().body)
            assertEquals("target-uuid-1", state.messages.first().replyToUuid)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `sendMessage clears replyTarget after successful send`() = runTest(testDispatcher) {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", null))
        val viewModel = createViewModel()

        val target = ChatMessage(
            uuid = "target-1", senderUuid = "p2", type = "text", body = "original msg",
            imageRef = null, createdAt = "2026-07-05T12:00:00Z",
        )
        viewModel.setReplyTarget(target)

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.replyTarget == null) state = awaitItem()
            assertEquals("target-1", state.replyTarget?.uuid)

            viewModel.sendMessage("reply text", "target-1")

            while (state.replyTarget != null) state = awaitItem()
            assertEquals(1, state.messages.size)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `setReplyTarget updates uiState with the target message`() = runTest(testDispatcher) {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", null))
        val viewModel = createViewModel()

        val target = ChatMessage(
            uuid = "target-2", senderUuid = "p2", type = "text", body = "reply to me",
            imageRef = null, createdAt = "2026-07-05T12:00:00Z",
        )

        viewModel.uiState.test {
            var state = awaitItem()
            assertNull(state.replyTarget)

            viewModel.setReplyTarget(target)

            while (state.replyTarget == null) state = awaitItem()
            assertEquals("target-2", state.replyTarget?.uuid)
            assertEquals("p2", state.replyTarget?.senderUuid)
            assertEquals("reply to me", state.replyTarget?.body)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `setReplyTarget with null clears the reply target`() = runTest(testDispatcher) {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", null))
        val viewModel = createViewModel()

        viewModel.setReplyTarget(ChatMessage(
            uuid = "t", senderUuid = "p2", type = "text", body = "x",
            imageRef = null, createdAt = "2026-07-05T12:00:00Z",
        ))

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.replyTarget == null) state = awaitItem()
            assertNotNull(state.replyTarget)

            viewModel.setReplyTarget(null)

            while (state.replyTarget != null) state = awaitItem()
            assertNull(state.replyTarget)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `sendImage forwards the caption and the reply target to the repository`() = runTest(testDispatcher) {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", null))
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.sendImage("content://photo.jpg", "Look at this", "target-3")

            while (state.messages.isEmpty()) state = awaitItem()
            val sent = state.messages.first()
            assertEquals("Look at this", sent.body)
            assertEquals("content://photo.jpg", sent.imageRef)
            assertEquals("target-3", sent.replyToUuid)
            cancelAndIgnoreRemainingEvents()
        }
        assertEquals(listOf("Look at this"), chatRepository.postedCaptions)
        assertEquals(listOf("target-3"), chatRepository.postedImageReplyToUuids)
    }

    @Test
    fun `sendImage without a caption posts a null caption`() = runTest(testDispatcher) {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", null))
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.sendImage("content://photo.jpg", null)

            while (state.messages.isEmpty()) state = awaitItem()
            assertNull(state.messages.first().body)
            cancelAndIgnoreRemainingEvents()
        }
        assertEquals(listOf<String?>(null), chatRepository.postedCaptions)
        assertEquals(listOf<String?>(null), chatRepository.postedImageReplyToUuids)
    }

    @Test
    fun `sendImage clears the reply target`() = runTest(testDispatcher) {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", null))
        val viewModel = createViewModel()

        viewModel.setReplyTarget(
            ChatMessage(
                uuid = "target-4", senderUuid = "p2", type = "image", body = null,
                imageRef = "earlier.jpg", createdAt = "2026-07-05T12:00:00Z",
            ),
        )

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.replyTarget == null) state = awaitItem()

            viewModel.sendImage("content://photo.jpg", "here", "target-4")

            while (state.replyTarget != null) state = awaitItem()
            assertNull(state.replyTarget)
            cancelAndIgnoreRemainingEvents()
        }
    }
}
