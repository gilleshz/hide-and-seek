package fr.gshz.hideandseek.feature.creategame

import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.ui.UiText
import fr.gshz.hideandseek.domain.model.BoundingBox
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.GtfsRealtimeProbe
import fr.gshz.hideandseek.domain.model.GtfsSourceState
import fr.gshz.hideandseek.domain.model.RulesVariant
import fr.gshz.hideandseek.domain.model.TransitCatalogSuggestion
import fr.gshz.hideandseek.domain.model.TransitLine
import fr.gshz.hideandseek.domain.repository.AreaInfo
import fr.gshz.hideandseek.fake.FakeGameRepository
import fr.gshz.hideandseek.fake.FakeSessionRepository
import fr.gshz.hideandseek.fake.httpException
import io.mockk.coEvery
import io.mockk.mockk
import java.io.IOException
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runCurrent
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
class CreateGameViewModelTest {

    private val gameRepository = FakeGameRepository()
    private val sessionRepository = FakeSessionRepository()
    private val connectionStore = mockk<ConnectionStore>(relaxed = true)

    /** Shared with runTest so the debounced previews advance on the test's virtual clock. */
    private val dispatcher = UnconfinedTestDispatcher()

    @BeforeEach
    fun setUp() {
        Dispatchers.setMain(dispatcher)
        coEvery { connectionStore.currentPlayerName() } returns "Alice"
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
    fun `the transit line query is stored`() {
        val viewModel = createViewModel()

        viewModel.onTransitLineQueryChange("M1")

        assertEquals("M1", viewModel.uiState.value.transitLineQuery)
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
    fun `toggling an area keeps the selected transit lines`() = runTest(dispatcher) {
        val viewModel = selectableViewModel("1" to "A")
        advanceUntilIdle()
        viewModel.toggleTransitLineGroup(group("A", "1"))

        viewModel.toggleArea(AreaInfo("456", "relation", "Nancy", 8))

        assertEquals(setOf("1"), viewModel.uiState.value.selectedTransitLines)
    }

    @Test
    fun `using the selected lines previews the line boundary`() = runTest(dispatcher) {
        gameRepository.lineBoundaryPreviewResult = Result.success(catalogBoundary)
        val viewModel = selectableViewModel("1" to "A")
        advanceUntilIdle()
        viewModel.toggleTransitLineGroup(group("A", "1"))
        advanceUntilIdle()

        viewModel.useLinesAsBoundary()
        advanceUntilIdle()

        assertTrue(viewModel.uiState.value.useLineBoundary)
        assertEquals(catalogBoundary, viewModel.uiState.value.boundaryGeoJson)
        assertFalse(viewModel.uiState.value.isLoadingBoundary)
        assertNull(viewModel.uiState.value.boundaryError)
        assertEquals(
            FakeGameRepository.LineBoundaryPreviewCall(
                osmLineIds = setOf("1"),
                gtfsLines = emptyList(),
                size = GameSize.Medium,
                edition = Edition.Metric,
            ),
            gameRepository.lineBoundaryPreviewCalls.single(),
        )
    }

    @Test
    fun `the line boundary cannot be used without selected lines`() = runTest(dispatcher) {
        val viewModel = createViewModel()

        viewModel.useLinesAsBoundary()
        advanceUntilIdle()

        assertFalse(viewModel.uiState.value.useLineBoundary)
        assertTrue(gameRepository.lineBoundaryPreviewCalls.isEmpty())
    }

    @Test
    fun `clearing the line boundary returns to the selected areas`() = runTest(dispatcher) {
        val viewModel = selectableViewModel("1" to "A")
        advanceUntilIdle()
        viewModel.toggleTransitLineGroup(group("A", "1"))
        advanceUntilIdle()
        viewModel.useLinesAsBoundary()
        advanceUntilIdle()

        viewModel.clearLineBoundary()
        advanceUntilIdle()

        assertFalse(viewModel.uiState.value.useLineBoundary)
        assertEquals(listOf("123"), gameRepository.boundaryPreviewAreas.last().map { it.osmId })
        assertEquals(1, gameRepository.lineBoundaryPreviewCalls.size)
    }

    @Test
    fun `choosing an area switches back to the area boundary`() = runTest(dispatcher) {
        val viewModel = selectableViewModel("1" to "A")
        advanceUntilIdle()
        viewModel.toggleTransitLineGroup(group("A", "1"))
        advanceUntilIdle()
        viewModel.useLinesAsBoundary()
        advanceUntilIdle()

        viewModel.toggleArea(AreaInfo("456", "relation", "Nancy", 8))
        advanceUntilIdle()

        assertFalse(viewModel.uiState.value.useLineBoundary)
        assertEquals(1, gameRepository.lineBoundaryPreviewCalls.size)
        assertEquals(
            listOf("123", "456"),
            gameRepository.boundaryPreviewAreas.last().map { it.osmId },
        )
    }

    @Test
    fun `clearing the selected lines clears the line boundary`() = runTest(dispatcher) {
        val viewModel = selectableViewModel("1" to "A")
        advanceUntilIdle()
        viewModel.toggleTransitLineGroup(group("A", "1"))
        advanceUntilIdle()
        viewModel.useLinesAsBoundary()
        advanceUntilIdle()

        viewModel.toggleTransitLineGroup(group("A", "1"))
        advanceUntilIdle()

        assertFalse(viewModel.uiState.value.useLineBoundary)
    }

    @Test
    fun `switching to a large game clears the line boundary`() = runTest(dispatcher) {
        val viewModel = selectableViewModel("1" to "A")
        advanceUntilIdle()
        viewModel.toggleTransitLineGroup(group("A", "1"))
        advanceUntilIdle()
        viewModel.useLinesAsBoundary()
        advanceUntilIdle()

        viewModel.onSizeChange(GameSize.Large)

        assertFalse(viewModel.uiState.value.useLineBoundary)
    }

    @Test
    fun `a line boundary failure maps the server error`() = runTest(dispatcher) {
        gameRepository.lineBoundaryPreviewResult = Result.failure(
            httpException(400, """{"errorKey":"area_fit.no_geometry"}"""),
        )
        val viewModel = selectableViewModel("1" to "A")
        advanceUntilIdle()
        viewModel.toggleTransitLineGroup(group("A", "1"))
        advanceUntilIdle()

        viewModel.useLinesAsBoundary()
        advanceUntilIdle()

        assertEquals(
            UiText.fromResource(R.string.area_fit_no_geometry),
            viewModel.uiState.value.boundaryError,
        )
        assertFalse(viewModel.uiState.value.isLoadingBoundary)
        assertNull(viewModel.uiState.value.boundaryGeoJson)
    }

    @Test
    fun `creating in line mode sends no areas`() = runTest(dispatcher) {
        val viewModel = selectableViewModel("1" to "A")
        advanceUntilIdle()
        viewModel.toggleTransitLineGroup(group("A", "1"))
        advanceUntilIdle()
        viewModel.useLinesAsBoundary()
        advanceUntilIdle()
        viewModel.onNameChange("Berlin")

        viewModel.createGame()
        advanceUntilIdle()

        assertEquals(emptyList<AreaInfo>(), gameRepository.lastCreateAreas)
        assertEquals(listOf("1"), gameRepository.lastCreateTransitLines?.map { it.osmId })
    }

    @Test
    fun `a blank name blocks creation`() = runTest {
        val viewModel = createViewModel()

        viewModel.createGame()

        assertEquals(ErrorType.Validation, viewModel.uiState.value.error)
        assertNull(gameRepository.lastJoinDisplayName)
    }

    @Test
    fun `creating a game joins as host with the stored player name`() = runTest {
        val viewModel = createViewModel()
        viewModel.onNameChange("Berlin")

        viewModel.createGame()

        assertEquals("Alice", gameRepository.lastJoinDisplayName)
        assertEquals("game-1", viewModel.uiState.value.createdGameUuid)
        assertNull(viewModel.uiState.value.error)
    }

    @Test
    fun `without a player name the create asks for one`() = runTest {
        coEvery { connectionStore.currentPlayerName() } returns null
        val viewModel = createViewModel()
        viewModel.onNameChange("Berlin")

        viewModel.createGame()

        assertTrue(viewModel.uiState.value.needsConnect)
        assertNull(gameRepository.lastJoinDisplayName)
        assertNull(viewModel.uiState.value.createdGameUuid)
    }

    @Test
    fun `a join failure after create keeps the game code for the reconnect`() = runTest {
        gameRepository.joinGameResult = Result.failure(
            httpException(400, """{"errorKey":"game.not_found"}"""),
        )
        val viewModel = createViewModel()
        viewModel.onNameChange("Berlin")

        viewModel.createGame()

        assertTrue(viewModel.uiState.value.needsConnect)
        assertEquals("game.not_found", viewModel.uiState.value.needConnectErrorKey)
        assertEquals("ABCDEF", viewModel.uiState.value.needConnectJoinCode)
        assertNull(viewModel.uiState.value.createdGameUuid)
    }

    @Test
    fun `a validation response surfaces the server detail`() = runTest {
        gameRepository.createGameResult = Result.failure(
            httpException(
                422,
                """{"detail":"selectedTransitLines: This collection should contain 250 elements or less."}""",
            ),
        )
        val viewModel = createViewModel()
        viewModel.onNameChange("Paris")

        viewModel.createGame()

        assertEquals(ErrorType.Validation, viewModel.uiState.value.error)
        assertEquals(
            "selectedTransitLines: This collection should contain 250 elements or less.",
            viewModel.uiState.value.errorDetail,
        )
        assertNull(viewModel.uiState.value.createdGameUuid)
    }

    @Test
    fun `a pasted realtime url is checked after the debounce`() = runTest(dispatcher) {
        gameRepository.probeGtfsRealtimeResult = Result.success(
            GtfsRealtimeProbe("ok", "trip_update", 20, 42),
        )
        val viewModel = createViewModel()

        viewModel.onGtfsRealtimeUrlChange("https://example.com/feed")
        advanceUntilIdle()

        assertEquals(
            RealtimeProbeState.Ok(tripCount = 42, ageSeconds = 20),
            viewModel.uiState.value.realtimeProbe,
        )
        assertEquals(listOf("https://example.com/feed"), gameRepository.probeGtfsRealtimeCalls)
    }

    @Test
    fun `a vehicle feed is reported as the wrong type`() = runTest(dispatcher) {
        gameRepository.probeGtfsRealtimeResult = Result.success(
            GtfsRealtimeProbe("wrong_type", "vehicle", 30, 0),
        )
        val viewModel = createViewModel()

        viewModel.onGtfsRealtimeUrlChange("https://example.com/vehicles")
        advanceUntilIdle()

        assertEquals(RealtimeProbeState.WrongType("vehicle"), viewModel.uiState.value.realtimeProbe)
    }

    @Test
    fun `an unreachable feed is reported`() = runTest(dispatcher) {
        gameRepository.probeGtfsRealtimeResult = Result.failure(IOException("offline"))
        val viewModel = createViewModel()

        viewModel.onGtfsRealtimeUrlChange("https://example.com/feed")
        advanceUntilIdle()

        assertEquals(RealtimeProbeState.Unreachable, viewModel.uiState.value.realtimeProbe)
    }

    @Test
    fun `clearing the realtime url clears the probe`() = runTest(dispatcher) {
        val viewModel = createViewModel()
        viewModel.onGtfsRealtimeUrlChange("https://example.com/feed")
        advanceUntilIdle()

        viewModel.onGtfsRealtimeUrlChange("")
        advanceUntilIdle()

        assertNull(viewModel.uiState.value.realtimeProbe)
    }

    @Test
    fun `a non-https realtime url is rejected without a request`() = runTest(dispatcher) {
        val viewModel = createViewModel()

        viewModel.onGtfsRealtimeUrlChange("http://example.com/feed")
        advanceUntilIdle()

        assertEquals(RealtimeProbeState.InvalidUrl, viewModel.uiState.value.realtimeProbe)
        assertTrue(gameRepository.probeGtfsRealtimeCalls.isEmpty())
    }

    @Test
    fun `a compact small game is sent as compact`() = runTest {
        val viewModel = createViewModel()
        viewModel.onNameChange("Berlin")
        viewModel.onSizeChange(GameSize.Small)
        viewModel.onCompactMapChange(true)

        viewModel.createGame()

        assertEquals(GameSize.Small, gameRepository.lastCreateSize)
        assertEquals(RulesVariant.Compact, gameRepository.lastCreateRulesVariant)
    }

    @Test
    fun `an official game is sent as official`() = runTest {
        val viewModel = createViewModel()
        viewModel.onNameChange("Berlin")

        viewModel.createGame()

        assertEquals(RulesVariant.Official, gameRepository.lastCreateRulesVariant)
    }

    @Test
    fun `switching away from small clears the compact flag`() = runTest {
        val viewModel = createViewModel()
        viewModel.onNameChange("Berlin")
        viewModel.onSizeChange(GameSize.Small)
        viewModel.onCompactMapChange(true)

        viewModel.onSizeChange(GameSize.Medium)
        viewModel.createGame()

        assertFalse(viewModel.uiState.value.compactMap)
        assertEquals(GameSize.Medium, gameRepository.lastCreateSize)
        assertEquals(RulesVariant.Official, gameRepository.lastCreateRulesVariant)
    }

    private val catalogBoundary =
        """{"type":"Polygon","coordinates":[[[1.0,48.0],[2.0,48.0],[2.0,49.0],[1.0,49.0],[1.0,48.0]]]}"""

    private fun catalogSuggestion(
        id: String = "feed-1",
        name: String = "Metz Metropole",
        provider: String = "transport.data.gouv.fr",
        live: Boolean = true,
    ) = TransitCatalogSuggestion(
        id = id,
        provider = provider,
        name = name,
        municipality = "Metz",
        countryCode = "FR",
        licenseUrl = "https://license",
        downloadUrl = "https://catalog/download.zip",
        fallbackUrl = "https://catalog/fallback.zip",
        realtimeUrl = "https://catalog/realtime.pb",
        live = live,
        scope = "regional",
    )

    private fun TestScope.openCatalogDialog(): CreateGameViewModel {
        gameRepository.boundaryPreviewResult = Result.success(catalogBoundary)
        val viewModel = createViewModel()
        viewModel.toggleArea(AreaInfo("123", "relation", "Metz", 8))
        advanceUntilIdle()
        viewModel.showGtfsDialog()
        advanceUntilIdle()
        return viewModel
    }

    @Test
    fun `the gtfs dialog starts in the networks mode`() = runTest(dispatcher) {
        val viewModel = createViewModel()

        viewModel.showGtfsDialog()
        advanceUntilIdle()

        assertEquals(GtfsDialogMode.Networks, viewModel.uiState.value.gtfsDialogMode)
    }

    @Test
    fun `the catalog is fetched only once the dialog is open`() = runTest(dispatcher) {
        gameRepository.boundaryPreviewResult = Result.success(catalogBoundary)
        val viewModel = createViewModel()
        viewModel.toggleArea(AreaInfo("123", "relation", "Metz", 8))
        advanceUntilIdle()

        assertTrue(gameRepository.searchTransitCatalogCalls.isEmpty())

        viewModel.showGtfsDialog()
        advanceUntilIdle()

        assertEquals(1, gameRepository.searchTransitCatalogCalls.size)
    }

    @Test
    fun `opening the dialog loads the catalog for the area boundary`() = runTest(dispatcher) {
        val suggestion = catalogSuggestion()
        gameRepository.searchTransitCatalogResult = Result.success(listOf(suggestion))

        val viewModel = openCatalogDialog()

        assertEquals(
            listOf(BoundingBox(swLat = 48.0, swLng = 1.0, neLat = 49.0, neLng = 2.0) to ""),
            gameRepository.searchTransitCatalogCalls,
        )
        assertEquals(listOf(suggestion), viewModel.uiState.value.catalogSuggestions)
        assertFalse(viewModel.uiState.value.isCatalogLoading)
        assertNull(viewModel.uiState.value.catalogError)
    }

    @Test
    fun `a catalog query is debounced into a single request`() = runTest(dispatcher) {
        val viewModel = openCatalogDialog()
        val callsAfterOpen = gameRepository.searchTransitCatalogCalls.size

        viewModel.onCatalogQueryChange("me")
        viewModel.onCatalogQueryChange("metz")
        assertEquals(callsAfterOpen, gameRepository.searchTransitCatalogCalls.size)

        advanceUntilIdle()

        assertEquals(callsAfterOpen + 1, gameRepository.searchTransitCatalogCalls.size)
        assertEquals("metz", gameRepository.searchTransitCatalogCalls.last().second)
    }

    @Test
    fun `an empty catalog response leaves no suggestions and no error`() = runTest(dispatcher) {
        gameRepository.searchTransitCatalogResult = Result.success(emptyList())

        val viewModel = openCatalogDialog()

        assertTrue(viewModel.uiState.value.catalogSuggestions.isEmpty())
        assertFalse(viewModel.uiState.value.isCatalogLoading)
        assertNull(viewModel.uiState.value.catalogError)
    }

    @Test
    fun `a catalog failure is shown and retrying loads it`() = runTest(dispatcher) {
        gameRepository.searchTransitCatalogResult = Result.failure(IOException("offline"))
        val viewModel = openCatalogDialog()

        assertEquals(UiText.fromResource(R.string.gtfs_catalog_error), viewModel.uiState.value.catalogError)
        assertFalse(viewModel.uiState.value.isCatalogLoading)

        val suggestion = catalogSuggestion()
        gameRepository.searchTransitCatalogResult = Result.success(listOf(suggestion))
        viewModel.retryCatalog()
        advanceUntilIdle()

        assertNull(viewModel.uiState.value.catalogError)
        assertEquals(listOf(suggestion), viewModel.uiState.value.catalogSuggestions)
    }

    @Test
    fun `without a boundary the catalog asks for the area first`() = runTest(dispatcher) {
        val viewModel = createViewModel()

        viewModel.showGtfsDialog()
        advanceUntilIdle()

        assertTrue(gameRepository.searchTransitCatalogCalls.isEmpty())
        assertEquals(UiText.fromResource(R.string.gtfs_catalog_preparing_area), viewModel.uiState.value.catalogError)
        assertFalse(viewModel.uiState.value.isCatalogLoading)
    }

    @Test
    fun `the catalog loads when the boundary arrives while the dialog is open`() = runTest(dispatcher) {
        val viewModel = createViewModel()
        viewModel.showGtfsDialog()
        advanceUntilIdle()
        assertTrue(gameRepository.searchTransitCatalogCalls.isEmpty())

        gameRepository.boundaryPreviewResult = Result.success(catalogBoundary)
        viewModel.toggleArea(AreaInfo("123", "relation", "Metz", 8))
        advanceUntilIdle()

        assertNull(viewModel.uiState.value.catalogError)
        assertEquals(1, gameRepository.searchTransitCatalogCalls.size)
    }

    @Test
    fun `adding an area clears the boundary until the new preview arrives`() = runTest(dispatcher) {
        gameRepository.boundaryPreviewResult = Result.success(catalogBoundary)
        val viewModel = createViewModel()
        viewModel.toggleArea(AreaInfo("123", "relation", "Metz", 8))
        advanceUntilIdle()
        assertNotNull(viewModel.uiState.value.boundaryGeoJson)

        viewModel.toggleArea(AreaInfo("456", "relation", "Nancy", 8))

        assertNull(viewModel.uiState.value.boundaryGeoJson)
    }

    @Test
    fun `a boundary preview for a superseded area selection is discarded`() = runTest(dispatcher) {
        val gate = CompletableDeferred<Unit>()
        gameRepository.boundaryPreviewGate = gate
        gameRepository.boundaryPreviewResult = Result.success(catalogBoundary)
        val viewModel = createViewModel()
        viewModel.toggleArea(AreaInfo("123", "relation", "Metz", 8))
        advanceUntilIdle()

        viewModel.toggleArea(AreaInfo("456", "relation", "Nancy", 8))
        gate.complete(Unit)
        runCurrent()

        assertNull(viewModel.uiState.value.boundaryGeoJson)
    }

    @Test
    fun `selecting a suggestion uploads it with fallback and realtime urls`() = runTest(dispatcher) {
        val suggestion = catalogSuggestion()
        val source = GtfsSourceState("src-2", suggestion.name, emptyList(), hasRealtime = true)
        gameRepository.uploadGtfsFromUrlResult = Result.success(source)
        val viewModel = openCatalogDialog()

        viewModel.onSelectCatalogSuggestion(suggestion)
        advanceUntilIdle()

        assertEquals(
            FakeGameRepository.GtfsUploadCall(
                source = "https://catalog/download.zip",
                name = "Metz Metropole",
                realtimeUrl = "https://catalog/realtime.pb",
                fallbackUrl = "https://catalog/fallback.zip",
            ),
            gameRepository.gtfsUploadCalls.single(),
        )
        assertFalse(viewModel.uiState.value.showGtfsDialog)
        assertEquals(listOf(source), viewModel.uiState.value.gtfsSources)
    }

    @Test
    fun `a suggestion without a name is uploaded under its provider`() = runTest(dispatcher) {
        val suggestion = catalogSuggestion(name = "", provider = "Open Data")
        gameRepository.uploadGtfsFromUrlResult = Result.success(
            GtfsSourceState("src-2", "Open Data", emptyList()),
        )
        val viewModel = openCatalogDialog()

        viewModel.onSelectCatalogSuggestion(suggestion)
        advanceUntilIdle()

        assertEquals("Open Data", gameRepository.gtfsUploadCalls.single().name)
    }

    @Test
    fun `a failed suggestion upload keeps the dialog open`() = runTest(dispatcher) {
        gameRepository.uploadGtfsFromUrlResult = Result.failure(IOException("offline"))
        val viewModel = openCatalogDialog()

        viewModel.onSelectCatalogSuggestion(catalogSuggestion())
        advanceUntilIdle()

        assertTrue(viewModel.uiState.value.showGtfsDialog)
        assertTrue(viewModel.uiState.value.gtfsError != null)
        assertTrue(viewModel.uiState.value.gtfsSources.isEmpty())
        assertFalse(viewModel.uiState.value.gtfsUploading)
    }

    @Test
    fun `reopening the dialog clears the catalog error`() = runTest(dispatcher) {
        gameRepository.searchTransitCatalogResult = Result.failure(IOException("offline"))
        val viewModel = openCatalogDialog()
        assertTrue(viewModel.uiState.value.catalogError != null)

        gameRepository.searchTransitCatalogResult = Result.success(emptyList())
        viewModel.dismissGtfsDialog()
        viewModel.showGtfsDialog()
        advanceUntilIdle()

        assertNull(viewModel.uiState.value.catalogError)
    }

    @Test
    fun `a missing shapes error is localized`() {
        assertEquals(
            UiText.fromResource(R.string.gtfs_shapes_missing),
            gtfsUploadError("gtfs.shapes_missing", "Invalid GTFS archive: Missing required file: shapes.txt"),
        )
        assertEquals(
            UiText.fromResource(R.string.gtfs_selection_too_large),
            gtfsUploadError("gtfs.selection_too_large", "too many"),
        )
        assertEquals(
            UiText.fromResource(R.string.gtfs_selection_empty),
            gtfsUploadError("gtfs.selection_empty", "empty"),
        )
        assertEquals(
            UiText.fromResource(R.string.gtfs_subset_too_large),
            gtfsUploadError("gtfs.subset_too_large", "too large"),
        )
        assertEquals(
            UiText.fromResource(R.string.gtfs_source_too_large),
            gtfsUploadError("gtfs.source_too_large", "too large"),
        )
        assertEquals(
            UiText.fromResource(R.string.gtfs_source_uncompressed_too_large),
            gtfsUploadError("gtfs.source_uncompressed_too_large", "too large"),
        )
        assertEquals(
            UiText.fromDynamic("other"),
            gtfsUploadError("gtfs.invalid_archive", "other"),
        )
    }
}
