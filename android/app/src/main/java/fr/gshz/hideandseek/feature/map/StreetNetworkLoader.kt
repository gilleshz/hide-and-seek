package fr.gshz.hideandseek.feature.map

import android.util.Log
import dagger.hilt.android.scopes.ViewModelScoped
import fr.gshz.hideandseek.core.model.PlayerSession
import fr.gshz.hideandseek.core.navigation.NavigationRequestStore
import fr.gshz.hideandseek.core.util.isSessionExpired
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.domain.model.StreetNetworkStatus
import fr.gshz.hideandseek.domain.repository.StreetNetworkRepository
import fr.gshz.hideandseek.di.DefaultDispatcher
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.withContext
import retrofit2.HttpException

/**
 * Parsing a dense payload and building the graph is order 10^6 operations, so it must not land on the
 * main thread while the hider is on a clock. The retry loop stays in the drawing ViewModel.
 */
@ViewModelScoped
class StreetNetworkLoader @Inject constructor(
    private val streetNetworkRepository: StreetNetworkRepository,
    private val navigationRequestStore: NavigationRequestStore,
    @DefaultDispatcher private val snapDispatcher: CoroutineDispatcher,
) {
    suspend fun fetch(session: PlayerSession): StreetNetworkFetch =
        withContext(snapDispatcher) {
            try {
                val network = streetNetworkRepository.getStreetNetwork(session.roundUuid)
                when {
                    network.status == StreetNetworkStatus.Pending -> StreetNetworkFetch.Warming
                    network.status != StreetNetworkStatus.Ready || network.ways.isEmpty() ->
                        StreetNetworkFetch.Missing
                    else -> StreetGraph(network.ways).let {
                        if (it.isEmpty) StreetNetworkFetch.Missing else StreetNetworkFetch.Built(it)
                    }
                }
            } catch (e: IOException) {
                Log.w(TAG, "Failed to load the street network", e)
                StreetNetworkFetch.Missing
            } catch (e: HttpException) {
                if (e.isSessionExpired()) navigationRequestStore.emitSessionExpired(e.serverErrorKey())
                Log.w(TAG, "Failed to load the street network", e)
                StreetNetworkFetch.Missing
            }
        }

    private companion object {
        const val TAG = "StreetNetworkLoader"
    }
}

/** [Warming] is the only outcome worth retrying: the server is inside its 30 s warm window. */
sealed interface StreetNetworkFetch {
    val graph: StreetGraph? get() = null

    data class Built(override val graph: StreetGraph) : StreetNetworkFetch

    data object Missing : StreetNetworkFetch

    data object Warming : StreetNetworkFetch
}
