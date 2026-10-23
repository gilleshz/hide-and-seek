package fr.gshz.hideandseek.feature.joingame

import androidx.lifecycle.SavedStateHandle
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.domain.model.JoinResult
import fr.gshz.hideandseek.domain.model.Profile
import fr.gshz.hideandseek.domain.repository.AvatarRepository
import fr.gshz.hideandseek.fake.FakeGameRepository
import fr.gshz.hideandseek.fake.FakeSessionRepository
import fr.gshz.hideandseek.fake.httpException
import io.mockk.coEvery
import io.mockk.coVerify
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
    private val avatarRepository = mockk<AvatarRepository>()
    private lateinit var viewModel: JoinGameViewModel

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(UnconfinedTestDispatcher())
        coEvery { connectionStore.currentPlayerName() } returns "Alice"
        coEvery { avatarRepository.bootstrap(any()) } returns Profile("uuid-1", "Alice", null, null)
        viewModel = createViewModel(SavedStateHandle())
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    private fun createViewModel(handle: SavedStateHandle) =
        JoinGameViewModel(gameRepository, sessionRepository, connectionStore, avatarRepository, handle)

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
    fun `join sends the stored player name`() = runTest {
        viewModel.onGameKeyChange("game-1")
        viewModel.join()

        assertEquals("Alice", gameRepository.lastJoinDisplayName)
        assertEquals("game-1", gameRepository.lastJoinGameUuid)
    }

    @Test
    fun `prefills the game key from the nav argument in upper case`() = runTest {
        val handle = SavedStateHandle(mapOf(HideAndSeekDestinations.JOIN_GAME_CODE_ARG to "abcd"))
        val prefilled = createViewModel(handle)

        assertEquals("ABCD", prefilled.uiState.value.gameKey)
    }

    @Test
    fun `a prefilled UUID-shaped game key keeps its case`() = runTest {
        val handle = SavedStateHandle(
            mapOf(HideAndSeekDestinations.JOIN_GAME_CODE_ARG to "550e8400-e29b-41d4-a716-446655440000"),
        )
        val prefilled = createViewModel(handle)

        assertEquals("550e8400-e29b-41d4-a716-446655440000", prefilled.uiState.value.gameKey)
    }

    @Test
    fun `prefills the error key from the nav arguments`() = runTest {
        val handle = SavedStateHandle(
            mapOf(HideAndSeekDestinations.JOIN_GAME_ERROR_KEY_ARG to "game.not_found"),
        )
        val prefilled = createViewModel(handle)

        assertEquals("game.not_found", prefilled.uiState.value.errorKey)
        assertEquals(ErrorType.Validation, prefilled.uiState.value.error)
    }

    @Test
    fun `a blank game key sets a validation error without calling the repository`() = runTest {
        viewModel.join()

        assertEquals(ErrorType.Validation, viewModel.uiState.value.error)
        assertNull(gameRepository.lastJoinDisplayName)
    }

    @Test
    fun `without a player identity the join asks for a display name`() = runTest {
        coEvery { connectionStore.currentPlayerName() } returns null
        viewModel.onGameKeyChange("game-1")

        viewModel.join()

        assertTrue(viewModel.uiState.value.needsDisplayName)
        assertEquals(ErrorType.Validation, viewModel.uiState.value.error)
        assertNull(gameRepository.lastJoinDisplayName)
        assertNull(viewModel.uiState.value.joinedGameUuid)
    }

    @Test
    fun `a fresh join bootstraps the identity before joining`() = runTest {
        coEvery { connectionStore.currentPlayerName() } returns null
        coEvery { avatarRepository.bootstrap("Alice") } returns Profile("uuid-1", "Alice", null, null)
        viewModel.onGameKeyChange("game-1")
        viewModel.onDisplayNameChange("Alice")

        viewModel.join()

        assertEquals("Alice", gameRepository.lastJoinDisplayName)
        assertEquals("game-1", viewModel.uiState.value.joinedGameUuid)
        coVerify { avatarRepository.bootstrap("Alice") }
        coVerify { connectionStore.savePlayerName("Alice") }
    }

    @Test
    fun `a failed identity bootstrap surfaces a network error and stays on the form`() = runTest {
        coEvery { connectionStore.currentPlayerName() } returns null
        coEvery { avatarRepository.bootstrap(any()) } throws IOException("offline")
        viewModel.onGameKeyChange("game-1")
        viewModel.onDisplayNameChange("Alice")

        viewModel.join()

        assertEquals(ErrorType.Network, viewModel.uiState.value.error)
        assertNull(gameRepository.lastJoinDisplayName)
        assertNull(viewModel.uiState.value.joinedGameUuid)
    }

    @Test
    fun `a rejected join surfaces its server error key`() = runTest {
        gameRepository.joinGameResult = Result.failure(
            httpException(400, """{"errorKey":"identity.token_invalid"}"""),
        )
        viewModel.onGameKeyChange("game-1")

        viewModel.join()

        assertEquals(ErrorType.Validation, viewModel.uiState.value.error)
        assertEquals("identity.token_invalid", viewModel.uiState.value.errorKey)
        assertEquals("game-1", gameRepository.lastJoinGameUuid)
    }

    @Test
    fun `unknown game key surfaces a not-found error`() = runTest {
        gameRepository.joinGameResult = Result.failure(httpException(404))

        viewModel.onGameKeyChange("unknown")
        viewModel.join()

        assertEquals(ErrorType.NotFound, viewModel.uiState.value.error)
        assertFalse(viewModel.uiState.value.needsDisplayName)
    }

    @Test
    fun `network failure surfaces a network error`() = runTest {
        gameRepository.joinGameResult = Result.failure(IOException())

        viewModel.onGameKeyChange("game-1")
        viewModel.join()

        assertEquals(ErrorType.Network, viewModel.uiState.value.error)
    }
}
