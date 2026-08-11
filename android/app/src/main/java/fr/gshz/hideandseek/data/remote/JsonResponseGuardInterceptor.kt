package fr.gshz.hideandseek.data.remote

import java.io.IOException
import javax.inject.Inject
import okhttp3.Interceptor
import okhttp3.Response

/**
 * Rejects an HTML error page served on a JSON API call before the kotlinx-serialization converter
 * sees it. An overloaded server can answer HTML with a 200 status, and decoding that crashes the
 * app. Matches only text/html, never JSON, images, or the Mercure SSE feed.
 */
class JsonResponseGuardInterceptor @Inject constructor() : Interceptor {

    override fun intercept(chain: Interceptor.Chain): Response {
        val response = chain.proceed(chain.request())
        val contentType = response.header(CONTENT_TYPE).orEmpty()
        if (contentType.contains(HTML, ignoreCase = true)) {
            response.close()
            throw IOException("Server returned an HTML error page instead of JSON")
        }
        return response
    }

    private companion object {
        const val CONTENT_TYPE = "Content-Type"
        const val HTML = "html"
    }
}
