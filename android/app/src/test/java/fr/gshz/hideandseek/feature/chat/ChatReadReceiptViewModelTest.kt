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
import fr.gshz.hideandseek.domain.model.ChatMessage
import fr.gshz.hideandseek.domain.model.ChatMessageReader
import fr.gshz.hideandseek.domain.model.ChatReadCursor
import fr.gshz.hideandseek.domain.repository.ChatEvent
import fr.gshz.hideandseek.domain.repository.ChatDeletedEvent
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
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNotNull
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class ChatReadReceiptViewModelTest {

    private val chatRepository = FakeChatRepository()
    private val sessionRepository = FakeSessionRepository()
    private val connectionStore = mockk<ConnectionStore>(relaxed = true)
    private val gameEventRepository = mockk<GameEventRepository>(relaxed = true)
    private val gameStateCache = GameStateCache(FakeGameRepository(), mockk<ClientConfigRepository>(relaxed = true))
    private val chatVisibilityTracker = ChatVisibilityTracker()
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

    private fun createViewModel(): ChatViewModel {
        val vm = ChatViewModel(
            chatRepository,
            sessionRepository,
            connectionStore,
            gameEventRepository,
            FakeQuestionRepository(),
            FakeLocationRepository(),
            chatVisibilityTracker,
            gameStateCache,
            mockk<ImageSaver>(),
            NavigationRequestStore(),
            SavedStateHandle(mapOf(HideAndSeekDestinations.CHAT_ARG to "game-1")),
        )
        testDispatcher.scheduler.advanceUntilIdle()
        return vm
    }

    @Test
    fun `read cursors are loaded into the state`() = runTest(testDispatcher) {
        chatRepository.readCursors += ChatReadCursor("p2", "Bob", "2026-07-05T12:05:00Z")
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.readCursors.isEmpty()) state = awaitItem()
            assertEquals("2026-07-05T12:05:00Z", state.readCursors["p2"])
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `resuming the screen reports the newest message as read`() = runTest(testDispatcher) {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", null))
        chatRepository.messages += listOf(
            ChatMessage(
                uuid = "1", senderUuid = "p2", type = "text", body = "first",
                imageRef = null, createdAt = "2026-07-05T12:00:00Z",
            ),
            ChatMessage(
                uuid = "2", senderUuid = "p2", type = "text", body = "second",
                imageRef = null, createdAt = "2026-07-05T12:01:00Z",
            ),
        )
        val viewModel = createViewModel()

        viewModel.onScreenResumed()
        testDispatcher.scheduler.advanceUntilIdle()

        assertEquals(listOf("2"), chatRepository.markedReadUpTo)
    }

    @Test
    fun `a read report is not repeated for the same newest message`() = runTest(testDispatcher) {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", null))
        chatRepository.messages += ChatMessage(
            uuid = "1", senderUuid = "p2", type = "text", body = "first",
            imageRef = null, createdAt = "2026-07-05T12:00:00Z",
        )
        val viewModel = createViewModel()

        viewModel.onScreenResumed()
        viewModel.onScreenResumed()
        testDispatcher.scheduler.advanceUntilIdle()

        assertEquals(listOf("1"), chatRepository.markedReadUpTo)
    }

    @Test
    fun `nothing is reported while the chat is not on screen`() = runTest(testDispatcher) {
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", null))
        chatRepository.messages += ChatMessage(
            uuid = "1", senderUuid = "p2", type = "text", body = "first",
            imageRef = null, createdAt = "2026-07-05T12:00:00Z",
        )
        createViewModel()
        testDispatcher.scheduler.advanceUntilIdle()

        assertTrue(chatRepository.markedReadUpTo.isEmpty())
    }

    @Test
    fun `a read event advances another players watermark`() = runTest(testDispatcher) {
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            chatReadEventFlow.emit(ChatReadEvent("p2", "Bob", "2026-07-05T12:10:00Z"))

            while (state.readCursors["p2"] == null) state = awaitItem()
            assertEquals("2026-07-05T12:10:00Z", state.readCursors["p2"])
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `an older read event never moves a watermark backwards`() = runTest(testDispatcher) {
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            chatReadEventFlow.emit(ChatReadEvent("p2", "Bob", "2026-07-05T12:10:00Z"))
            while (state.readCursors["p2"] == null) state = awaitItem()

            chatReadEventFlow.emit(ChatReadEvent("p2", "Bob", "2026-07-05T12:00:00Z"))
            testDispatcher.scheduler.advanceUntilIdle()

            assertEquals("2026-07-05T12:10:00Z", viewModel.uiState.value.readCursors["p2"])
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `opening message info loads the readers of that message`() = runTest(testDispatcher) {
        chatRepository.readersByMessage["m-1"] = listOf(
            ChatMessageReader("p2", "Bob", "2026-07-05T12:03:00Z"),
        )
        val viewModel = createViewModel()

        viewModel.uiState.test {
            var state = awaitItem()

            viewModel.openMessageInfo("m-1")

            while (state.messageInfo?.isLoading != false) state = awaitItem()
            val info = assertNotNull(state.messageInfo).let { state.messageInfo!! }
            assertEquals("m-1", info.messageUuid)
            assertEquals(listOf("Bob"), info.readers.map { it.playerName })
            assertFalse(info.failed)
            cancelAndIgnoreRemainingEvents()
        }
        assertEquals(listOf("m-1"), chatRepository.requestedReaderMessageUuids)
    }

    @Test
    fun `closing message info clears it`() = runTest(testDispatcher) {
        val viewModel = createViewModel()

        viewModel.openMessageInfo("m-1")
        testDispatcher.scheduler.advanceUntilIdle()
        viewModel.closeMessageInfo()
        testDispatcher.scheduler.advanceUntilIdle()

        assertNull(viewModel.uiState.value.messageInfo)
    }
}
