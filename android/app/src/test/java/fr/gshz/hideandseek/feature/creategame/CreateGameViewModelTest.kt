package fr.gshz.hideandseek.feature.creategame

import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.AccountCredential
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.ui.UiText
import fr.gshz.hideandseek.domain.model.TransitLine
import fr.gshz.hideandseek.domain.repository.AreaInfo
import fr.gshz.hideandseek.fake.FakeGameRepository
import fr.gshz.hideandseek.fake.FakeSessionRepository
import fr.gshz.hideandseek.fake.httpException
import io.mockk.coEvery
import io.mockk.mockk
import java.io.IOException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
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
class CreateGameViewModelTest {

    private val gameRepository = FakeGameRepository()
    private val sessionRepository = FakeSessionRepository()
    private val connectionStore = mockk<ConnectionStore>(relaxed = true)

    /** Shared with runTest so the debounced previews advance on the test's virtual clock. */
    private val dispatcher = UnconfinedTestDispatcher()

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(dispatcher)
        coEvery { connectionStore.currentAccount() } returns AccountCredential("Alice", "p@ss")
    }

    @AfterEach
    fun tearDown() {
        Dispatchers.resetMain()
    }

    private fun createViewModel() =
        CreateGameViewModel(gameRepository, sessionRepository, connectionStore, dispatcher)

    @Test
    fun `default discovery mode selection is the rail modes only`() = runTest {
        val viewModel = createViewModel()

        assertEquals(DEFAULT_DISCOVERY_MODES, viewModel.uiState.value.discoveryModeSelection)
    }

    @Test
    fun `showing the discovery mode dialog flips the flag`() = runTest {
        val viewModel = createViewModel()

        viewModel.showDiscoveryModeDialog()

        assertTrue(viewModel.uiState.value.showDiscoveryModeDialog)
    }

    @Test
    fun `dismissing the discovery mode dialog clears the flag`() = runTest {
        val viewModel = createViewModel()
        viewModel.showDiscoveryModeDialog()

        viewModel.dismissDiscoveryModeDialog()

        assertFalse(viewModel.uiState.value.showDiscoveryModeDialog)
    }

    @Test
    fun `toggle all selects every mode when not all are selected`() = runTest {
        val viewModel = createViewModel()

        viewModel.toggleAllDiscoveryModes()

        assertEquals(ALL_ROUTE_TYPES, viewModel.uiState.value.discoveryModeSelection)
    }

    @Test
    fun `toggle all clears the selection when every mode is already selected`() = runTest {
        val viewModel = createViewModel()
        viewModel.toggleAllDiscoveryModes()

        viewModel.toggleAllDiscoveryModes()

        assertTrue(viewModel.uiState.value.discoveryModeSelection.isEmpty())
    }

    @Test
    fun `selecting an area resets the discovery mode selection to the rail defaults`() = runTest {
        val viewModel = createViewModel()
        viewModel.toggleAllDiscoveryModes()

        viewModel.toggleArea(AreaInfo("123", "relation", "Berlin", adminLevel = 6))

        assertEquals(DEFAULT_DISCOVERY_MODES, viewModel.uiState.value.discoveryModeSelection)
    }

    private fun group(ref: String, vararg osmIds: String) = TransitLineGroup(
        ref = ref,
        name = ref,
        nameEn = "",
        colour = "",
        routeType = "train",
        network = "SBB",
        osmIds = osmIds.toSet(),
    )

    @Test
    fun `bulk toggle selects every line in the given groups`() = runTest {
        val viewModel = createViewModel()

        viewModel.toggleTransitLineGroups(listOf(group("S1", "1", "2"), group("S2", "3")))

        assertEquals(setOf("1", "2", "3"), viewModel.uiState.value.selectedTransitLines)
    }

    @Test
    fun `bulk toggle clears the groups when every one is already selected`() = runTest {
        val viewModel = createViewModel()
        val groups = listOf(group("S1", "1", "2"), group("S2", "3"))
        viewModel.toggleTransitLineGroups(groups)

        viewModel.toggleTransitLineGroups(groups)

        assertTrue(viewModel.uiState.value.selectedTransitLines.isEmpty())
    }

    @Test
    fun `bulk toggle completes a partial selection instead of clearing it`() = runTest {
        val viewModel = createViewModel()
        val partial = group("S1", "1", "2")
        viewModel.toggleTransitLineGroup(partial)

        viewModel.toggleTransitLineGroups(listOf(partial, group("S2", "3")))

        assertEquals(setOf("1", "2", "3"), viewModel.uiState.value.selectedTransitLines)
    }

    @Test
    fun `bulk toggle leaves lines outside the given groups untouched`() = runTest {
        val viewModel = createViewModel()
        viewModel.toggleTransitLineGroup(group("R1", "9"))
        val groups = listOf(group("S1", "1"))
        viewModel.toggleTransitLineGroups(groups)

        viewModel.toggleTransitLineGroups(groups)

        assertEquals(setOf("9"), viewModel.uiState.value.selectedTransitLines)
    }

    private fun line(osmId: String, ref: String) = TransitLine(
        osmId = osmId,
        osmType = "relation",
        ref = ref,
        name = "Line $ref",
        colour = "#00AA00",
        routeType = "tram",
        network = "TAMM",
        operator = "TAMM",
    )

    private fun feature(osmId: String) =
        """{"type":"Feature","properties":{"osmId":"$osmId"},"geometry":{"type":"MultiLineString"}}"""

    private fun collection(vararg osmIds: String) =
        osmIds.joinToString(",", """{"type":"FeatureCollection","features":[""", "]}") { feature(it) }

    private fun selectableViewModel(vararg refs: Pair<String, String>): CreateGameViewModel {
        gameRepository.discoverTransitLinesResult = Result.success(
            refs.map { (osmId, ref) -> line(osmId, ref) }.ifEmpty { listOf(line("1", "A")) },
        )
        val viewModel = createViewModel()
        viewModel.toggleArea(AreaInfo("123", "relation", "Metz", 8))
        viewModel.discoverOsmTransit()
        return viewModel
    }

    @Test
    fun `no selection means no request and nothing drawn`() = runTest(dispatcher) {
        val viewModel = selectableViewModel()

        advanceUntilIdle()

        assertEquals(0, gameRepository.transitLinePreviewCalls)
        assertNull(viewModel.uiState.value.transitPreviewGeoJson)
    }

    @Test
    fun `ticking a line draws its geometry once the debounce elapses`() = runTest(dispatcher) {
        gameRepository.transitLinePreviewResult = Result.success(collection("1"))
        val viewModel = selectableViewModel()

        viewModel.toggleTransitLineGroup(group("A", "1"))
        assertNull(viewModel.uiState.value.transitPreviewGeoJson)
        advanceUntilIdle()

        assertEquals(setOf("1"), gameRepository.transitLinePreviewOsmIds)
        assertEquals(collection("1"), viewModel.uiState.value.transitPreviewGeoJson)
        assertFalse(viewModel.uiState.value.isLoadingTransitPreview)
    }

    @Test
    fun `a burst of ticks costs a single request`() = runTest(dispatcher) {
        gameRepository.transitLinePreviewResult = Result.success(collection("1", "2", "3"))
        val viewModel = selectableViewModel("1" to "A", "2" to "B", "3" to "C")

        viewModel.toggleTransitLineGroup(group("A", "1"))
        viewModel.toggleTransitLineGroup(group("B", "2"))
        viewModel.toggleTransitLineGroup(group("C", "3"))
        advanceUntilIdle()

        assertEquals(1, gameRepository.transitLinePreviewCalls)
        assertEquals(setOf("1", "2", "3"), gameRepository.transitLinePreviewOsmIds)
    }

    @Test
    fun `adding a line only fetches the one that is missing`() = runTest(dispatcher) {
        gameRepository.transitLinePreviewResult = Result.success(collection("1"))
        val viewModel = selectableViewModel("1" to "A", "2" to "B")
        viewModel.toggleTransitLineGroup(group("A", "1"))
        advanceUntilIdle()

        gameRepository.transitLinePreviewResult = Result.success(collection("2"))
        viewModel.toggleTransitLineGroup(group("B", "2"))
        advanceUntilIdle()

        assertEquals(setOf("2"), gameRepository.transitLinePreviewOsmIds)
        assertEquals(2, gameRepository.transitLinePreviewCalls)
        val drawn = viewModel.uiState.value.transitPreviewGeoJson
        assertTrue(drawn!!.contains(feature("1")))
        assertTrue(drawn.contains(feature("2")))
    }

    @Test
    fun `unticking a line redraws without hitting the network`() = runTest(dispatcher) {
        gameRepository.transitLinePreviewResult = Result.success(collection("1", "2"))
        val viewModel = selectableViewModel("1" to "A", "2" to "B")
        viewModel.toggleTransitLineGroup(group("A", "1"))
        viewModel.toggleTransitLineGroup(group("B", "2"))
        advanceUntilIdle()
        val callsAfterFirstDraw = gameRepository.transitLinePreviewCalls

        viewModel.toggleTransitLineGroup(group("B", "2"))
        advanceUntilIdle()

        assertEquals(callsAfterFirstDraw, gameRepository.transitLinePreviewCalls)
        val drawn = viewModel.uiState.value.transitPreviewGeoJson
        assertTrue(drawn!!.contains(feature("1")))
        assertFalse(drawn.contains(feature("2")))
    }

    @Test
    fun `unticking every line clears the preview`() = runTest(dispatcher) {
        gameRepository.transitLinePreviewResult = Result.success(collection("1"))
        val viewModel = selectableViewModel()
        viewModel.toggleTransitLineGroup(group("A", "1"))
        advanceUntilIdle()

        viewModel.toggleTransitLineGroup(group("A", "1"))
        advanceUntilIdle()

        assertNull(viewModel.uiState.value.transitPreviewGeoJson)
    }

    @Test
    fun `a preview of lines without geometry says so and is not asked for again`() = runTest(dispatcher) {
        gameRepository.transitLinePreviewResult = Result.failure(
            httpException(400, """{"errorKey":"transit.preview_no_geometry"}"""),
        )
        val viewModel = selectableViewModel("1" to "A", "2" to "B")
        viewModel.toggleTransitLineGroup(group("A", "1"))
        advanceUntilIdle()

        assertEquals(
            UiText.fromResource(R.string.transit_preview_no_geometry),
            viewModel.uiState.value.transitPreviewError,
        )
        assertFalse(viewModel.uiState.value.isLoadingTransitPreview)

        gameRepository.transitLinePreviewResult = Result.success(collection("2"))
        viewModel.toggleTransitLineGroup(group("B", "2"))
        advanceUntilIdle()

        assertEquals(setOf("2"), gameRepository.transitLinePreviewOsmIds)
    }

    @Test
    fun `a preview network failure reports the network error`() = runTest(dispatcher) {
        gameRepository.transitLinePreviewResult = Result.failure(IOException("offline"))
        val viewModel = selectableViewModel()

        viewModel.toggleTransitLineGroup(group("A", "1"))
        advanceUntilIdle()

        assertEquals(
            UiText.fromResource(R.string.transit_preview_network_error),
            viewModel.uiState.value.transitPreviewError,
        )
    }

    @Test
    fun `retrying refetches a line the previous attempt failed on`() = runTest(dispatcher) {
        gameRepository.transitLinePreviewResult = Result.failure(IOException("offline"))
        val viewModel = selectableViewModel()
        viewModel.toggleTransitLineGroup(group("A", "1"))
        advanceUntilIdle()

        gameRepository.transitLinePreviewResult = Result.success(collection("1"))
        viewModel.retryTransitPreview()
        advanceUntilIdle()

        assertNull(viewModel.uiState.value.transitPreviewError)
        assertEquals(collection("1"), viewModel.uiState.value.transitPreviewGeoJson)
    }

    @Test
    fun `a burst of area ticks costs a single boundary request`() = runTest(dispatcher) {
        val viewModel = createViewModel()

        viewModel.toggleArea(AreaInfo("1", "relation", "Metz", 8))
        viewModel.toggleArea(AreaInfo("2", "relation", "Nancy", 8))
        viewModel.toggleArea(AreaInfo("3", "relation", "Toul", 8))
        advanceUntilIdle()

        assertEquals(1, gameRepository.boundaryPreviewCalls)
    }

    @Test
    fun `a blank name blocks creation`() = runTest {
        val viewModel = createViewModel()

        viewModel.createGame()

        assertEquals(ErrorType.Validation, viewModel.uiState.value.error)
        assertNull(gameRepository.lastPassword)
    }

    @Test
    fun `creating a game joins as host with the stored account credential`() = runTest {
        val viewModel = createViewModel()
        viewModel.onNameChange("Berlin")

        viewModel.createGame()

        assertEquals("p@ss", gameRepository.lastPassword)
        assertEquals("Alice", gameRepository.lastJoinDisplayName)
        assertEquals("game-1", viewModel.uiState.value.createdGameUuid)
        assertNull(viewModel.uiState.value.error)
    }

    @Test
    fun `without an account credential the create asks for one`() = runTest {
        coEvery { connectionStore.currentAccount() } returns null
        val viewModel = createViewModel()
        viewModel.onNameChange("Berlin")

        viewModel.createGame()

        assertTrue(viewModel.uiState.value.needsAccount)
        assertNull(gameRepository.lastPassword)
        assertNull(viewModel.uiState.value.createdGameUuid)
    }
}
