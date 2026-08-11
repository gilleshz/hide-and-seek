package fr.gshz.hideandseek.data.remote

import fr.gshz.hideandseek.core.data.SessionStore
import javax.inject.Inject
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import okhttp3.Interceptor
import okhttp3.Response

/**
 * Attaches the subscriber token to every API call once a session exists, mirroring [ApiKeyInterceptor].
 * The token is the per-player identity (backend IdentityResolver), so dropping the explicit
 * `@Header` parameters on the two hider GETs leaves a single source of truth here.
 */
class SubscriberTokenInterceptor @Inject constructor(
    private val sessionStore: SessionStore,
) : Interceptor {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    @Volatile
    private var cachedToken: String? = null

    init {
        scope.launch {
            sessionStore.session.collect { session ->
                cachedToken = session?.mercureToken
            }
        }
    }

    override fun intercept(chain: Interceptor.Chain): Response {
        val token = cachedToken
        val request = if (token != null) {
            chain.request().newBuilder().addHeader(HEADER, token).build()
        } else {
            chain.request()
        }
        return chain.proceed(request)
    }

    private companion object {
        const val HEADER = "X-Subscriber-Token"
    }
}
