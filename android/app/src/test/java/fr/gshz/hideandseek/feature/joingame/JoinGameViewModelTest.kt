package fr.gshz.hideandseek.feature.joingame

import androidx.lifecycle.SavedStateHandle
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.AccountCredential
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.domain.model.JoinResult
import fr.gshz.hideandseek.fake.FakeGameRepository
import fr.gshz.hideandseek.fake.FakeSessionRepository
import fr.gshz.hideandseek.fake.httpException
import io.mockk.coEvery
import io.mockk.mockk
import java.io.IOException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.Test

@OptIn(ExperimentalCoroutinesApi::class)
class JoinGameViewModelTest {

    private val gameRepository = FakeGameRepository()
    private val sessionRepository = FakeSessionRepository()
    private val connectionStore = mockk<ConnectionStore>(relaxed = true)
    private lateinit var viewModel: JoinGameViewModel

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(UnconfinedTestDispatcher())
        coEvery { connectionStore.currentAccount() } returns AccountCredential("Alice", "p@ss")
        viewModel = JoinGameViewModel(gameRepository, sessionRepository, connectionStore, SavedStateHandle())
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun `join succeeds and saves the round from the join response directly`() = runTest {
        gameRepository.joinGameResult =
            Result.success(JoinResult("player-1", "Alice", "game-1", "round-1", "token", listOf("t")))

        viewModel.onGameKeyChange("game-1")
        viewModel.join()

        val state = viewModel.uiState.value
        assertEquals("game-1", state.joinedGameUuid)
        assertNull(state.error)
        assertEquals("round-1", sessionRepository.currentSession()?.roundUuid)
    }

    @Test
    fun `a side-less join session never carries location topics`() = runTest {
        gameRepository.joinGameResult = Result.success(
            JoinResult(
                "player-1", "Alice", "game-1", "round-1", "token",
                listOf("game/game-1/chat", "game/game-1/round/round-1/hider-locations"),
            ),
        )

        viewModel.onGameKeyChange("game-1")
        viewModel.join()

        val topics = sessionRepository.currentSession()?.topics ?: emptyList()
        assertTrue(topics.none { it.endsWith("-locations") })
        assertTrue(topics.contains("game/game-1/chat"))
    }

    @Test
    fun `join sends the stored account name and password`() = runTest {
        viewModel.onGameKeyChange("game-1")
        viewModel.join()

        assertEquals("p@ss", gameRepository.lastPassword)
        assertEquals("Alice", gameRepository.lastJoinDisplayName)
        assertEquals("game-1", gameRepository.lastJoinGameUuid)
    }

    @Test
    fun `prefills the game key from the nav argument in upper case`() = runTest {
        val handle = SavedStateHandle(mapOf(HideAndSeekDestinations.JOIN_GAME_CODE_ARG to "abcd"))
        val prefilled = JoinGameViewModel(gameRepository, sessionRepository, connectionStore, handle)

        assertEquals("ABCD", prefilled.uiState.value.gameKey)
    }

    @Test
    fun `a prefilled UUID-shaped game key keeps its case`() = runTest {
        val handle = SavedStateHandle(
            mapOf(HideAndSeekDestinations.JOIN_GAME_CODE_ARG to "550e8400-e29b-41d4-a716-446655440000"),
        )
        val prefilled = JoinGameViewModel(gameRepository, sessionRepository, connectionStore, handle)

        assertEquals("550e8400-e29b-41d4-a716-446655440000", prefilled.uiState.value.gameKey)
    }

    @Test
    fun `prefills the error key from the nav arguments`() = runTest {
        val handle = SavedStateHandle(
            mapOf(HideAndSeekDestinations.JOIN_GAME_ERROR_KEY_ARG to "join.password_invalid"),
        )
        val prefilled = JoinGameViewModel(gameRepository, sessionRepository, connectionStore, handle)

        assertEquals("join.password_invalid", prefilled.uiState.value.errorKey)
        assertEquals(ErrorType.Validation, prefilled.uiState.value.error)
    }

    @Test
    fun `a blank game key sets a validation error without calling the repository`() = runTest {
        viewModel.join()

        assertEquals(ErrorType.Validation, viewModel.uiState.value.error)
        assertNull(gameRepository.lastPassword)
    }

    @Test
    fun `without an account credential the join asks for one`() = runTest {
        coEvery { connectionStore.currentAccount() } returns null
        viewModel.onGameKeyChange("game-1")

        viewModel.join()

        assertTrue(viewModel.uiState.value.needsAccount)
        assertNull(viewModel.uiState.value.needAccountErrorKey)
        assertNull(gameRepository.lastPassword)
        assertNull(viewModel.uiState.value.joinedGameUuid)
    }

    @Test
    fun `a rejected account credential asks for one with the error key`() = runTest {
        gameRepository.joinGameResult = Result.failure(
            httpException(400, """{"errorKey":"join.password_invalid"}"""),
        )
        viewModel.onGameKeyChange("game-1")

        viewModel.join()

        assertTrue(viewModel.uiState.value.needsAccount)
        assertEquals("join.password_invalid", viewModel.uiState.value.needAccountErrorKey)
        assertEquals("game-1", gameRepository.lastJoinGameUuid)
    }

    @Test
    fun `unknown game key surfaces a not-found error`() = runTest {
        gameRepository.joinGameResult = Result.failure(httpException(404))

        viewModel.onGameKeyChange("unknown")
        viewModel.join()

        assertEquals(ErrorType.NotFound, viewModel.uiState.value.error)
        assertFalse(viewModel.uiState.value.needsAccount)
    }

    @Test
    fun `network failure surfaces a network error`() = runTest {
        gameRepository.joinGameResult = Result.failure(IOException())

        viewModel.onGameKeyChange("game-1")
        viewModel.join()

        assertEquals(ErrorType.Network, viewModel.uiState.value.error)
        assertFalse(viewModel.uiState.value.needsAccount)
    }
}
