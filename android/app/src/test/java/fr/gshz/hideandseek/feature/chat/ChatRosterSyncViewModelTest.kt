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
import fr.gshz.hideandseek.domain.model.Player
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
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class ChatRosterSyncViewModelTest {

    private val chatRepository = FakeChatRepository()
    private val gameRepository = FakeGameRepository()
    private val sessionRepository = FakeSessionRepository()
    private val connectionStore = mockk<ConnectionStore>(relaxed = true)
    private val gameEventRepository = mockk<GameEventRepository>(relaxed = true)
    private val gameStateCache = GameStateCache(gameRepository, mockk<ClientConfigRepository>(relaxed = true))
    private val testDispatcher = UnconfinedTestDispatcher()

    private val chatEventFlow = MutableSharedFlow<ChatEvent>(extraBufferCapacity = 8)
    private val rosterEventFlow = MutableSharedFlow<RosterChanged>(extraBufferCapacity = 8)
    private val reconnectedEventFlow = MutableSharedFlow<ReconnectedEvent>(extraBufferCapacity = 8)
    private val chatReadEventFlow = MutableSharedFlow<ChatReadEvent>(extraBufferCapacity = 8)
    private val chatDeletedEventFlow = MutableSharedFlow<ChatDeletedEvent>(extraBufferCapacity = 8)

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(testDispatcher)
        every { gameEventRepository.chatEvents } returns chatEventFlow
        every { gameEventRepository.rosterEvents } returns rosterEventFlow
        every { gameEventRepository.reconnectedEvents } returns reconnectedEventFlow
        every { gameEventRepository.chatReadEvents } returns chatReadEventFlow
        every { gameEventRepository.chatDeletedEvents } returns chatDeletedEventFlow
        sessionRepository.seed(PlayerSession("game-1", "round-1", "p1", "Alice", "token", null))
        gameRepository.listPlayersResult = Result.success(listOf(Player("p1", "Alice")))
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun `a roster event picks up a player who joined after the chat opened without refetching`() =
        runTest(testDispatcher) {
            val viewModel = createViewModel()
            val callsAfterLoad = gameRepository.listPlayersCalls

            rosterEventFlow.emit(RosterChanged(listOf(Player("p1", "Alice"), Player("p3", "Carol"))))
            testDispatcher.scheduler.advanceUntilIdle()

            viewModel.uiState.test {
                var state = awaitItem()
                while (state.roster.size < 2) state = awaitItem()
                assertEquals("Carol", state.roster["p3"])
                assertEquals(callsAfterLoad, gameRepository.listPlayersCalls)
                cancelAndIgnoreRemainingEvents()
            }
        }

    @Test
    fun `a message from an unknown sender resyncs the roster from the API`() = runTest(testDispatcher) {
        val viewModel = createViewModel()

        // The stamped name differs from the roster's, so only an actual refetch can produce "Carol".
        gameRepository.listPlayersResult = Result.success(listOf(Player("p1", "Alice"), Player("p3", "Carol")))
        chatEventFlow.emit(unknownSenderEvent(stampedName = "stale-stamp"))
        testDispatcher.scheduler.advanceUntilIdle()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.roster.size < 2) state = awaitItem()
            assertEquals("Carol", state.roster["p3"])
            assertEquals("Carol", state.senderNameOf(state.messages.single()))
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `an unknown sender only ever costs one roster refetch`() = runTest(testDispatcher) {
        gameRepository.listPlayersResult = Result.failure(IOException("offline"))
        createViewModel()
        val callsAfterLoad = gameRepository.listPlayersCalls

        repeat(4) { index -> chatEventFlow.emit(unknownSenderEvent(uuid = "m-$index")) }
        testDispatcher.scheduler.advanceUntilIdle()

        assertEquals(1, gameRepository.listPlayersCalls - callsAfterLoad)
    }

    @Test
    fun `a message names its sender even while the roster stays stale`() = runTest(testDispatcher) {
        gameRepository.listPlayersResult = Result.failure(IOException("offline"))
        val viewModel = createViewModel()

        chatEventFlow.emit(unknownSenderEvent())
        testDispatcher.scheduler.advanceUntilIdle()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.messages.isEmpty()) state = awaitItem()
            assertEquals("Carol", state.senderNameOf(state.messages.single()))
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `reconnecting refetches the roster`() = runTest(testDispatcher) {
        val viewModel = createViewModel()

        gameRepository.listPlayersResult = Result.success(listOf(Player("p1", "Alice"), Player("p3", "Carol")))
        reconnectedEventFlow.emit(ReconnectedEvent)
        testDispatcher.scheduler.advanceUntilIdle()

        viewModel.uiState.test {
            var state = awaitItem()
            while (state.roster.size < 2) state = awaitItem()
            assertEquals("Carol", state.roster["p3"])
            cancelAndIgnoreRemainingEvents()
        }
    }

    private fun unknownSenderEvent(uuid: String = "m-9", stampedName: String = "Carol") = ChatEvent(
        uuid = uuid,
        senderUuid = "p3",
        senderName = stampedName,
        messageType = "text",
        body = "Just got here",
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
            ChatVisibilityTracker(),
            gameStateCache,
            mockk<ImageSaver>(),
            NavigationRequestStore(),
            SavedStateHandle(mapOf(HideAndSeekDestinations.CHAT_ARG to "game-1")),
        )
        testDispatcher.scheduler.advanceUntilIdle()
        return vm
    }
}
