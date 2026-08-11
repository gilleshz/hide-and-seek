package fr.gshz.hideandseek.data.remote

import fr.gshz.hideandseek.core.data.ConnectionStore
import javax.inject.Inject
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import okhttp3.Interceptor
import okhttp3.Response

class ApiKeyInterceptor @Inject constructor(
    private val connectionStore: ConnectionStore,
) : Interceptor {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    @Volatile
    private var cachedApiKey: String? = null

    init {
        scope.launch {
            connectionStore.connectionConfig.collect { config ->
                cachedApiKey = config?.apiKey
            }
        }
    }

    override fun intercept(chain: Interceptor.Chain): Response {
        val apiKey = cachedApiKey
        val request = chain.request().let { original ->
            if (apiKey != null) {
                original.newBuilder().addHeader(HEADER, apiKey).build()
            } else {
                original
            }
        }
        return chain.proceed(request)
    }

    private companion object {
        const val HEADER = "X-API-KEY"
    }
}
