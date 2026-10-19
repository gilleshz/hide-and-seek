package fr.gshz.hideandseek.feature.settings

import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import fr.gshz.hideandseek.core.data.SettingsStore
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@HiltViewModel
class SettingsViewModel @Inject constructor(
    private val settingsStore: SettingsStore,
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
}
