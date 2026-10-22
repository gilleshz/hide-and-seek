package fr.gshz.hideandseek.feature.settings

import android.graphics.Bitmap
import android.util.Log
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.data.SettingsStore
import fr.gshz.hideandseek.core.model.ErrorType
import fr.gshz.hideandseek.core.util.serverErrorArgs
import fr.gshz.hideandseek.core.util.serverErrorKey
import fr.gshz.hideandseek.core.util.toErrorType
import fr.gshz.hideandseek.di.DefaultDispatcher
import fr.gshz.hideandseek.domain.repository.AvatarRepository
import fr.gshz.hideandseek.feature.settings.avatar.toAvatarJpeg
import java.io.IOException
import javax.inject.Inject
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import retrofit2.HttpException

@HiltViewModel
class SettingsViewModel @Inject constructor(
    private val settingsStore: SettingsStore,
    private val connectionStore: ConnectionStore,
    private val avatarRepository: AvatarRepository,
    @DefaultDispatcher private val ioDispatcher: CoroutineDispatcher,
) : ViewModel() {

    private val _uiState = MutableStateFlow(SettingsUiState())
    val uiState: StateFlow<SettingsUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch {
            settingsStore.locale.collect { savedTag ->
                _uiState.update { it.copy(selectedLocaleTag = savedTag) }
            }
        }
        viewModelScope.launch {
            settingsStore.themeMode.collect { savedTheme ->
                _uiState.update { it.copy(selectedTheme = savedTheme) }
            }
        }
        viewModelScope.launch {
            connectionStore.playerName.distinctUntilChanged().collect { name ->
                _uiState.update {
                    it.copy(playerName = name, avatarUrl = if (name == null) null else it.avatarUrl)
                }
                if (name != null) refreshProfile()
            }
        }
    }

    /**
     * Applies the selected locale immediately via AppCompat and persists the choice.
     *
     * The locale change is synchronous so the caller can trigger activity recreation
     * right after this returns. The DataStore write runs async in the background.
     */
    fun onLocaleSelected(tag: String) {
        if (tag.isEmpty()) {
            AppCompatDelegate.setApplicationLocales(LocaleListCompat.getEmptyLocaleList())
        } else {
            AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags(tag))
        }
        viewModelScope.launch {
            if (tag.isEmpty()) {
                settingsStore.clearLocale()
            } else {
                settingsStore.saveLocale(tag)
            }
            _uiState.update { it.copy(selectedLocaleTag = tag.ifEmpty { null }) }
        }
    }

    fun onThemeSelected(mode: String) {
        viewModelScope.launch {
            settingsStore.saveThemeMode(mode)
            _uiState.update { it.copy(selectedTheme = mode) }
        }
    }

    fun uploadAvatar(bitmap: Bitmap) = launchAvatarRequest {
        val jpeg = withContext(ioDispatcher) { bitmap.toAvatarJpeg() }
        val avatarUrl = avatarRepository.avatarUrl(avatarRepository.uploadAvatar(jpeg))
        _uiState.update { it.copy(avatarUrl = avatarUrl, avatarSaved = true) }
    }

    fun removeAvatar() = launchAvatarRequest {
        avatarRepository.removeAvatar()
        _uiState.update { it.copy(avatarUrl = null) }
    }

    fun consumeAvatarSaved() {
        _uiState.update { it.copy(avatarSaved = false) }
    }

    fun clearAvatarError() {
        _uiState.update { it.copy(avatarError = null, avatarErrorKey = null, avatarErrorArgs = null) }
    }

    private suspend fun refreshProfile() {
        try {
            val profile = avatarRepository.getProfile()
            _uiState.update { it.copy(avatarUrl = profile.avatarUrl) }
        } catch (e: IOException) {
            Log.w(TAG, "Avatar profile unavailable", e)
        } catch (e: HttpException) {
            Log.w(TAG, "Avatar profile rejected", e)
        }
    }

    private fun launchAvatarRequest(request: suspend () -> Unit) {
        viewModelScope.launch {
            _uiState.update {
                it.copy(isUploadingAvatar = true, avatarError = null, avatarErrorKey = null, avatarErrorArgs = null)
            }
            try {
                request()
                _uiState.update { it.copy(isUploadingAvatar = false) }
            } catch (e: HttpException) {
                failAvatar(e.toErrorType(), e.serverErrorKey(), e.serverErrorArgs())
            } catch (e: IOException) {
                Log.w(TAG, "Avatar request failed", e)
                failAvatar(ErrorType.Network)
            } catch (e: IllegalStateException) {
                Log.w(TAG, "Avatar encoding failed", e)
                failAvatar(ErrorType.Unknown)
            }
        }
    }

    private fun failAvatar(error: ErrorType, errorKey: String? = null, errorArgs: Map<String, String>? = null) {
        _uiState.update {
            it.copy(
                isUploadingAvatar = false,
                avatarError = error,
                avatarErrorKey = errorKey,
                avatarErrorArgs = errorArgs,
            )
        }
    }

    private companion object {
        const val TAG = "SettingsViewModel"
    }
}
