package fr.gshz.hideandseek

import android.app.Application
import android.content.Context
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat
import dagger.Lazy
import dagger.hilt.android.HiltAndroidApp
import coil3.ImageLoader
import coil3.SingletonImageLoader
import fr.gshz.hideandseek.core.data.SettingsStore
import fr.gshz.hideandseek.core.token.TokenRefresher
import fr.gshz.hideandseek.di.MapLibreHttpClient
import javax.inject.Inject
import org.maplibre.android.MapLibre
import org.maplibre.android.module.http.HttpRequestImpl
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

/**
 * Coil's `AsyncImage` uses the Hilt-provided [ImageLoader], whose OkHttp client attaches the
 * X-API-KEY header: chat image GETs are guarded by the backend like every other /api route.
 */
@HiltAndroidApp
class HideAndSeekApp : Application(), SingletonImageLoader.Factory {

    @Inject
    lateinit var imageLoader: Lazy<ImageLoader>

    @Inject
    lateinit var settingsStore: SettingsStore

    @Inject
    lateinit var tokenRefresher: TokenRefresher

    private val applicationScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onCreate() {
        super.onCreate()
        // HttpRequestImpl's static init needs MapLibre registered first; the swap precedes the first tile request.
        MapLibre.getInstance(this)
        HttpRequestImpl.setOkHttpClient(MapLibreHttpClient.build())
        // Reading the persisted locale is async: never block startup on the DataStore read.
        applicationScope.launch {
            val locale = settingsStore.locale.first()
            if (!locale.isNullOrEmpty()) {
                AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags(locale))
            }
        }
        tokenRefresher.start()
    }

    override fun newImageLoader(context: Context): ImageLoader = imageLoader.get()
}
