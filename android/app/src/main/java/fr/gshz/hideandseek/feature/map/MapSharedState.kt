package fr.gshz.hideandseek.feature.map

import androidx.lifecycle.SavedStateHandle
import dagger.hilt.android.scopes.ViewModelScoped
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.data.GameStateCache
import fr.gshz.hideandseek.core.navigation.HideAndSeekDestinations
import fr.gshz.hideandseek.core.navigation.NavigationRequestStore
import fr.gshz.hideandseek.core.util.isSessionExpired
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.domain.repository.GameEventRepository
import fr.gshz.hideandseek.domain.repository.GameRepository
import fr.gshz.hideandseek.domain.repository.ManualConstraintRepository
import fr.gshz.hideandseek.domain.repository.PossibleAreaRepository
import fr.gshz.hideandseek.domain.repository.QuestionPreviewRepository
import fr.gshz.hideandseek.domain.repository.QuestionRepository
import fr.gshz.hideandseek.domain.repository.SessionRepository
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow
import retrofit2.HttpException

/**
 * The map route's context: which game this screen is on, its session, the game events and the
 * navigation requests. The SavedStateHandle argument is read exactly here, so no ViewModel owns it.
 */
@ViewModelScoped
class MapSessionEvents @Inject constructor(
    savedStateHandle: SavedStateHandle,
    val sessionRepository: SessionRepository,
    val gameEventRepository: GameEventRepository,
    val navigationRequestStore: NavigationRequestStore,
) {
    val gameUuid: String = checkNotNull(savedStateHandle[HideAndSeekDestinations.MAP_ARG])

    fun handleSessionExpiry(e: HttpException) {
        if (e.isSessionExpired()) navigationRequestStore.emitSessionExpired(e.serverErrorKey())
    }
}

/** The question sheet's data sources, grouped to keep the ViewModel constructor within budget. */
@ViewModelScoped
class MapQuestionSources @Inject constructor(
    val questionRepository: QuestionRepository,
    val questionPreviewRepository: QuestionPreviewRepository,
    val possibleAreaRepository: PossibleAreaRepository,
    val connectionStore: ConnectionStore,
)

/** The drawing device's constraint source plus the refresh its edits must trigger on the map. */
@ViewModelScoped
class ManualConstraintSource @Inject constructor(
    val manualConstraintRepository: ManualConstraintRepository,
    val possibleAreaRefreshFlow: PossibleAreaRefreshFlow,
)

/** Game summary, transit overlay and client config: the three sources behind the game's identity. */
@ViewModelScoped
class MapGameInfoSource @Inject constructor(
    val gameStateCache: GameStateCache,
    val gameRepository: GameRepository,
    val connectionStore: ConnectionStore,
)

/** Bumping restarts the round poll, so a zone card or a sprung trap is reflected at once. */
@ViewModelScoped
class RoundRefreshFlow @Inject constructor() {
    val refresh = MutableStateFlow(0)
}

/** Bumping restarts the possible-area poll, so a manual constraint change narrows the map at once. */
@ViewModelScoped
class PossibleAreaRefreshFlow @Inject constructor() {
    val refresh = MutableStateFlow(0)
}

/**
 * A trace request preempts an open zone placement panel, so the drawing concern signals the zone
 * concern. The replay keeps the cancel even if the zone ViewModel subscribes after the request lands.
 */
@ViewModelScoped
class ZonePlacementCancelSignal @Inject constructor() {
    private val _cancellations = MutableSharedFlow<Unit>(replay = 1, extraBufferCapacity = 1)
    val cancellations: SharedFlow<Unit> = _cancellations.asSharedFlow()

    fun requestCancellation() {
        _cancellations.tryEmit(Unit)
    }
}
