package fr.gshz.hideandseek.di

import android.content.Context
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import fr.gshz.hideandseek.BuildConfig
import fr.gshz.hideandseek.data.remote.ApiKeyInterceptor
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.JsonResponseGuardInterceptor
import fr.gshz.hideandseek.data.remote.SubscriberTokenInterceptor
import java.io.File
import java.util.concurrent.TimeUnit
import coil3.ImageLoader
import coil3.network.okhttp.OkHttpNetworkFetcherFactory
import javax.inject.Named
import javax.inject.Singleton
import kotlinx.serialization.json.Json
import okhttp3.Cache
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory

/**
 * Hilt wiring for the networking stack, all singletons. `@Provides` is required for classes whose
 * constructors we don't own (OkHttp, Retrofit). The placeholder base URL is never used: [HideAndSeekApi]
 * takes a full `@Url` on every call because the real API host is entered at runtime.
 */
@Module
@InstallIn(SingletonComponent::class)
object NetworkModule {

    private const val PLACEHOLDER_BASE_URL = "http://localhost/"
    // Outlasts the server's own limits so a tile build reports its real error, not a socket timeout.
    private const val CREATE_GAME_TIMEOUT_SECONDS = 480L
    private const val CACHE_SIZE_BYTES = 10L * 1024 * 1024

    @Provides
    @Singleton
    fun provideJson(): Json = Json { ignoreUnknownKeys = true }

    @Provides
    @Singleton
    fun provideOkHttpClient(
        apiKeyInterceptor: ApiKeyInterceptor,
        subscriberTokenInterceptor: SubscriberTokenInterceptor,
        jsonGuard: JsonResponseGuardInterceptor,
        @ApplicationContext context: Context,
    ): OkHttpClient =
        OkHttpClient.Builder()
            .addInterceptor(apiKeyInterceptor)
            .addInterceptor(subscriberTokenInterceptor)
            .addInterceptor(jsonGuard)
            .apply {
                // Body logging prints the API key header and the player token, so it never ships.
                if (BuildConfig.DEBUG) {
                    addInterceptor(
                        HttpLoggingInterceptor().apply { level = HttpLoggingInterceptor.Level.BODY },
                    )
                }
            }
            .cache(Cache(File(context.cacheDir, "http_cache"), CACHE_SIZE_BYTES))
            .build()

    @Provides
    @Singleton
    fun provideRetrofit(client: OkHttpClient, json: Json): Retrofit =
        Retrofit.Builder()
            .baseUrl(PLACEHOLDER_BASE_URL)
            .client(client)
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()

    @Provides
    @Singleton
    fun provideHideAndSeekApi(retrofit: Retrofit): HideAndSeekApi = retrofit.create(HideAndSeekApi::class.java)

    @Provides
    @Singleton
    @Named("longTimeout")
    fun provideLongTimeoutOkHttpClient(
        apiKeyInterceptor: ApiKeyInterceptor,
        subscriberTokenInterceptor: SubscriberTokenInterceptor,
        jsonGuard: JsonResponseGuardInterceptor,
    ): OkHttpClient =
        OkHttpClient.Builder()
            .addInterceptor(apiKeyInterceptor)
            .addInterceptor(subscriberTokenInterceptor)
            .addInterceptor(jsonGuard)
            .apply {
                // Body logging prints the API key header and the player names, so it never ships.
                if (BuildConfig.DEBUG) {
                    addInterceptor(
                        HttpLoggingInterceptor().apply { level = HttpLoggingInterceptor.Level.BODY },
                    )
                }
            }
            .readTimeout(CREATE_GAME_TIMEOUT_SECONDS, TimeUnit.SECONDS)
            .build()

    @Provides
    @Singleton
    @Named("longTimeout")
    fun provideLongTimeoutRetrofit(
        @Named("longTimeout") client: OkHttpClient,
        json: Json,
    ): Retrofit =
        Retrofit.Builder()
            .baseUrl(PLACEHOLDER_BASE_URL)
            .client(client)
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()

    @Provides
    @Singleton
    @Named("longTimeout")
    fun provideLongTimeoutHideAndSeekApi(@Named("longTimeout") retrofit: Retrofit): HideAndSeekApi =
        retrofit.create(HideAndSeekApi::class.java)

    @Provides
    @Singleton
    @Named("image")
    fun provideImageOkHttpClient(apiKeyInterceptor: ApiKeyInterceptor): OkHttpClient =
        OkHttpClient.Builder()
            .addInterceptor(apiKeyInterceptor)
            .build()

    @Provides
    @Singleton
    fun provideImageLoader(
        @ApplicationContext context: Context,
        @Named("image") client: OkHttpClient,
    ): ImageLoader =
        ImageLoader.Builder(context)
            .components { add(OkHttpNetworkFetcherFactory(callFactory = { client })) }
            .build()
}
