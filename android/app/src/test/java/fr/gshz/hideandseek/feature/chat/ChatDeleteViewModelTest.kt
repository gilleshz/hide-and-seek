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
import java.io.IOException
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
class ChatDeleteViewModelTest {

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
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", null))
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun `deleting a message reports it and scrubs it locally`() = runTest(testDispatcher) {
        chatRepository.messages += ownMessage()
        val viewModel = createViewModel()

        viewModel.deleteMessage("m-1")
        testDispatcher.scheduler.advanceUntilIdle()

        assertEquals(listOf("m-1"), chatRepository.deletedMessageUuids)
        assertEquals(listOf("p1"), chatRepository.deletedByPlayerUuids)
        viewModel.uiState.test {
            var state = awaitItem()
            while (state.messages.isEmpty()) state = awaitItem()
            assertTrue(state.messages.single().deleted)
            assertNull(state.messages.single().body)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `an incoming deletion event scrubs the message without a request`() = runTest(testDispatcher) {
        chatRepository.messages += ChatMessage(
            uuid = "m-9", senderUuid = "p2", type = "text", body = "Their secret",
            imageRef = "photo.jpg", createdAt = "2026-07-05T12:00:00Z",
        )
        val viewModel = createViewModel()

        chatDeletedEventFlow.emit(ChatDeletedEvent("m-9"))
        testDispatcher.scheduler.advanceUntilIdle()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.messages.isEmpty()) state = awaitItem()
            val message = state.messages.single()
            assertTrue(message.deleted)
            assertNull(message.body)
            assertNull(message.imageRef)
            cancelAndIgnoreRemainingEvents()
        }
        assertTrue(chatRepository.deletedMessageUuids.isEmpty())
    }

    @Test
    fun `a deleted message keeps its place and sender in the conversation`() = runTest(testDispatcher) {
        chatRepository.messages += listOf(
            ownMessage(),
            ChatMessage(
                uuid = "m-2", senderUuid = "p2", type = "text", body = "Reply",
                imageRef = null, createdAt = "2026-07-05T12:01:00Z", replyToUuid = "m-1",
            ),
        )
        val viewModel = createViewModel()

        viewModel.deleteMessage("m-1")
        testDispatcher.scheduler.advanceUntilIdle()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.messages.isEmpty()) state = awaitItem()
            assertEquals(listOf("m-1", "m-2"), state.messages.map { it.uuid })
            assertEquals("p1", state.messages.first().senderUuid)
            assertEquals("m-1", state.messages.last().replyToUuid)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `the open info sheet closes when its message is deleted`() = runTest(testDispatcher) {
        chatRepository.messages += ownMessage()
        val viewModel = createViewModel()

        viewModel.openMessageInfo("m-1")
        testDispatcher.scheduler.advanceUntilIdle()
        viewModel.uiState.test {
            var state = awaitItem()
            while (state.messageInfo == null) state = awaitItem()
            assertNotNull(state.messageInfo)

            viewModel.deleteMessage("m-1")
            while (state.messageInfo != null) state = awaitItem()
            assertTrue(state.messages.single().deleted)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `a failed deletion surfaces an error and leaves the message intact`() = runTest(testDispatcher) {
        chatRepository.messages += ownMessage()
        chatRepository.deleteResult = Result.failure(IOException("offline"))
        val viewModel = createViewModel()

        viewModel.deleteMessage("m-1")
        testDispatcher.scheduler.advanceUntilIdle()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.messages.isEmpty()) state = awaitItem()
            assertNotNull(state.sendError)
            assertFalse(state.messages.single().deleted)
            assertEquals("Regretted", state.messages.single().body)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `only text and image messages a player still owns are deletable`() {
        val text = ownMessage()
        assertTrue(text.isDeletable)
        assertTrue(text.copy(type = "image", imageRef = "photo.jpg").isDeletable)
        assertFalse(text.copy(type = "question", questionUuid = "q-1").isDeletable)
        assertFalse(text.copy(type = "answer", questionUuid = "q-1").isDeletable)
        assertFalse(text.copy(type = "question_info").isDeletable)
        assertFalse(text.copy(type = "system", senderUuid = null).isDeletable)
        assertFalse(text.asDeleted().isDeletable)
    }

    @Test
    fun `an unknown deletion event leaves the conversation untouched`() = runTest(testDispatcher) {
        chatRepository.messages += ownMessage()
        val viewModel = createViewModel()

        chatDeletedEventFlow.emit(ChatDeletedEvent("m-unknown"))
        testDispatcher.scheduler.advanceUntilIdle()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.messages.isEmpty()) state = awaitItem()
            assertFalse(state.messages.single().deleted)
            cancelAndIgnoreRemainingEvents()
        }
    }

    private fun ownMessage() = ChatMessage(
        uuid = "m-1",
        senderUuid = "p1",
        type = "text",
        body = "Regretted",
        imageRef = null,
        createdAt = "2026-07-05T12:00:00Z",
    )

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
}
