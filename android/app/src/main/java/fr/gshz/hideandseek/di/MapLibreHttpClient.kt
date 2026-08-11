package fr.gshz.hideandseek.di

import fr.gshz.hideandseek.BuildConfig
import okhttp3.OkHttpClient

/**
 * MapLibre's tile/font fetches run through its own OkHttp client whose default User-Agent only
 * names the library; tile usage policies ask for an identifying UA, so the client is swapped via
 * the public HttpRequestImpl.setOkHttpClient hook and the header is overridden here.
 */
object MapLibreHttpClient {

    fun build(): OkHttpClient = OkHttpClient.Builder()
        .addInterceptor { chain ->
            val request = chain.request().newBuilder()
                .header("User-Agent", "JetLag-HideSeek/${BuildConfig.VERSION_NAME}")
                .build()
            chain.proceed(request)
        }
        .build()
}
