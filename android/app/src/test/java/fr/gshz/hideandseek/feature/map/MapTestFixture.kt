package fr.gshz.hideandseek.feature.map

import android.net.Uri
import androidx.lifecycle.SavedStateHandle
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.data.GameStateCache
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.core.navigation.NavigationRequestStore
import fr.gshz.hideandseek.domain.model.ClientConfig
import fr.gshz.hideandseek.domain.repository.ClientConfigRepository
import fr.gshz.hideandseek.domain.repository.QuestionPreviewRepository
import fr.gshz.hideandseek.domain.repository.QuestionRepository
import fr.gshz.hideandseek.fake.FakeGameEventRepository
import fr.gshz.hideandseek.fake.FakeGameRepository
import fr.gshz.hideandseek.fake.FakeLocationRepository
import fr.gshz.hideandseek.fake.FakeManualConstraintRepository
import fr.gshz.hideandseek.fake.FakePossibleAreaRepository
import fr.gshz.hideandseek.fake.FakeRoundRepository
import fr.gshz.hideandseek.fake.FakeSeekerMarkerRepository
import fr.gshz.hideandseek.fake.FakeSessionRepository
import fr.gshz.hideandseek.fake.FakeStreetNetworkRepository
import fr.gshz.hideandseek.fake.FakeTimeTrapRepository
import fr.gshz.hideandseek.fake.FakeTraceImageWriter
import fr.gshz.hideandseek.fake.FakeZoneRepository
import io.mockk.coEvery
import io.mockk.every
import io.mockk.mockk
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.test.UnconfinedTestDispatcher

/** One wiring for every map ViewModel under test, mirroring what Hilt provides on the map route. */
class MapTestFixture(
    private val dispatcher: CoroutineDispatcher = UnconfinedTestDispatcher(),
    val gameUuid: String = "game-1",
) {
    val locationRepository = FakeLocationRepository()
    val gameRepository = FakeGameRepository()
    val sessionRepository = FakeSessionRepository()
    val zoneRepository = FakeZoneRepository()
    val streetNetworkRepository = FakeStreetNetworkRepository()
    val seekerMarkerRepository = FakeSeekerMarkerRepository()
    val manualConstraintRepository = FakeManualConstraintRepository()
    val roundRepository = FakeRoundRepository()
    val timeTrapRepository = FakeTimeTrapRepository()
    val possibleAreaRepository = FakePossibleAreaRepository()
    val gameEventRepository = FakeGameEventRepository()
    val connectionStore = mockk<ConnectionStore>()
    val questionRepository = mockk<QuestionRepository>()
    val questionPreviewRepository = mockk<QuestionPreviewRepository>()
    val clientConfigRepository = mockk<ClientConfigRepository>()
    val gameStateCache = GameStateCache(gameRepository, clientConfigRepository)
    val navigationRequestStore = NavigationRequestStore()
    val traceUri = mockk<Uri>()
    val traceImageWriter = FakeTraceImageWriter(traceUri)
    val sessionEvents = MapSessionEvents(
        SavedStateHandle(mapOf(HideAndSeekDestinations.MAP_ARG to gameUuid)),
        sessionRepository,
        gameEventRepository,
        navigationRequestStore,
    )
    val gameInfoSource = MapGameInfoSource(gameStateCache, gameRepository, connectionStore)
    val questionSources = MapQuestionSources(
        questionRepository, questionPreviewRepository, possibleAreaRepository, connectionStore,
    )
    val roundRefreshFlow = RoundRefreshFlow()
    val possibleAreaRefreshFlow = PossibleAreaRefreshFlow()
    val manualConstraintSource = ManualConstraintSource(manualConstraintRepository, possibleAreaRefreshFlow)
    val zonePlacementCancelSignal = ZonePlacementCancelSignal()
    val streetNetworkLoader = StreetNetworkLoader(streetNetworkRepository, navigationRequestStore, dispatcher)

    fun setUp() {
        coEvery { connectionStore.current() } returns null
        coEvery { questionRepository.listQuestions(any()) } returns emptyList()
        coEvery { clientConfigRepository.getClientConfig(any()) } returns
            ClientConfig(null, null, null, false, emptyList())
        every { traceUri.toString() } returns TRACE_IMAGE_URI
    }

    fun createSessionViewModel() = MapSessionViewModel(
        locationRepository, roundRepository, sessionEvents, gameInfoSource, roundRefreshFlow,
    )

    fun createZoneViewModel() = ZoneViewModel(
        zoneRepository, locationRepository, sessionEvents, roundRefreshFlow, zonePlacementCancelSignal,
    )

    fun createDrawingViewModel() = DrawingViewModel(
        streetNetworkLoader, manualConstraintSource, questionRepository, traceImageWriter,
        sessionEvents, zonePlacementCancelSignal,
    )

    fun createTimeTrapViewModel(
        savedStateHandle: SavedStateHandle = SavedStateHandle(mapOf(HideAndSeekDestinations.MAP_ARG to gameUuid)),
    ) = TimeTrapViewModel(timeTrapRepository, savedStateHandle, sessionEvents, roundRefreshFlow)

    fun createSeekerMarkersViewModel() = SeekerMarkersViewModel(seekerMarkerRepository, sessionEvents)

    fun createQuestionViewModel() = QuestionViewModel(
        questionSources, locationRepository, sessionEvents, possibleAreaRefreshFlow,
    )

    companion object {
        const val TRACE_IMAGE_URI = "content://fr.gshz.hideandseek.fileprovider/traces/trace.png"
    }
}
